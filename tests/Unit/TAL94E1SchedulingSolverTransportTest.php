<?php

namespace Tests\Unit;

use App\Actions\Integrations\SchedulingSolver\CloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\CloudRunSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\LocalHttpSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverTransportException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class TAL94E1SchedulingSolverTransportTest extends TestCase
{
    public function test_local_http_driver_resolves_in_testing_environment(): void
    {
        config()->set('tala_integrations.scheduling_solver.driver', 'local_http');
        config()->set('tala_integrations.scheduling_solver.url', 'http://127.0.0.1:8080');

        $this->app->forgetInstance(SchedulingSolverClient::class);
        $this->app->forgetInstance(LocalHttpSchedulingSolverClient::class);

        $this->assertInstanceOf(
            LocalHttpSchedulingSolverClient::class,
            app(SchedulingSolverClient::class),
        );
    }

    public function test_local_http_probe_and_solve_use_loopback_without_authorization(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:8080/health' => Http::response([
                'status' => 'ok',
                'service' => 'tala-scheduler-solver',
                'model_version' => 'tal94-demand-v2',
            ]),
            'http://127.0.0.1:8080/solve' => Http::response([
                'solver_run_id' => 55,
                'solver_status' => 'optimal',
                'assignments' => [],
            ]),
        ]);

        $client = $this->localClient();

        $probe = $client->probe();
        $result = $client->solve([
            'contract_version' => 'tal94-demand-v2',
            'run_metadata' => ['solver_run_id' => 55],
        ]);

        $this->assertSame(200, $probe['status']);
        $this->assertStringContainsString('tal94-demand-v2', $probe['body']);
        $this->assertSame('optimal', $result['solver_status']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8080/health'
            && $request->method() === 'GET'
            && ! $request->hasHeader('Authorization'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:8080/solve'
            && $request->method() === 'POST'
            && ! $request->hasHeader('Authorization')
            && $request['contract_version'] === 'tal94-demand-v2');
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidLocalUrls')]
    public function test_local_http_rejects_endpoints_outside_the_exact_loopback_boundary(string $url): void
    {
        Http::preventStrayRequests();

        $client = new LocalHttpSchedulingSolverClient(
            baseUrl: $url,
            timeoutSeconds: 30,
            connectTimeoutSeconds: 2,
            environment: 'testing',
        );

        try {
            $client->probe();
            $this->fail('An endpoint outside the local HTTP boundary was accepted.');
        } catch (SchedulingSolverTransportException $exception) {
            $this->assertSame('Local scheduling solver URL must use HTTP on an exact loopback host with no path, credentials, query, or fragment.', $exception->getMessage());
            $this->assertSame(SchedulingSolverTransportException::ClassificationConfiguration, $exception->classification());
            $this->assertFalse($exception->isRetryable());
        }

        Http::assertNothingSent();
    }

    public function test_local_http_is_rejected_outside_local_or_testing_environments(): void
    {
        Http::preventStrayRequests();

        $client = new LocalHttpSchedulingSolverClient(
            baseUrl: 'http://localhost:8080',
            timeoutSeconds: 30,
            connectTimeoutSeconds: 2,
            environment: 'production',
        );

        try {
            $client->probe();
            $this->fail('The local HTTP transport was accepted in production.');
        } catch (SchedulingSolverTransportException $exception) {
            $this->assertSame('Local scheduling solver transport is available only in local or testing environments.', $exception->getMessage());
            $this->assertSame(SchedulingSolverTransportException::ClassificationConfiguration, $exception->classification());
            $this->assertFalse($exception->isRetryable());
        }

        Http::assertNothingSent();
    }

    public function test_local_http_failed_solve_is_sent_only_once(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:8080/solve' => Http::response(['status' => 'failed'], 500),
        ]);

        try {
            $this->localClient()->solve(['contract_version' => 'tal94-demand-v2']);
            $this->fail('A failed solver response did not throw.');
        } catch (SchedulingSolverTransportException $exception) {
            $this->assertSame('Local scheduling solver request failed.', $exception->getMessage());
            $this->assertSame(SchedulingSolverTransportException::ClassificationServerError, $exception->classification());
            $this->assertSame(500, $exception->statusCode());
            $this->assertTrue($exception->isRetryable());
        }

        Http::assertSentCount(1);
    }

    #[DataProvider('httpFailureCases')]
    public function test_local_http_classifies_retryable_and_permanent_http_failures(
        int $status,
        string $classification,
        bool $retryable,
    ): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:8080/solve' => Http::response(['secret' => 'must-not-leak'], $status),
        ]);

        try {
            $this->localClient()->solve(['contract_version' => 'tal94-demand-v2']);
            $this->fail("HTTP {$status} did not throw a typed solver transport exception.");
        } catch (SchedulingSolverTransportException $exception) {
            $this->assertSame($classification, $exception->classification());
            $this->assertSame($status, $exception->statusCode());
            $this->assertSame($retryable, $exception->isRetryable());
            $this->assertStringNotContainsString('must-not-leak', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_local_http_connection_and_malformed_response_failures_are_classified_without_nested_retries(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://127.0.0.1:8080/solve' => Http::sequence()
                ->pushFailedConnection('connection refused at private endpoint')
                ->push('not-json', 200, ['Content-Type' => 'text/plain']),
        ]);

        try {
            $this->localClient()->solve(['contract_version' => 'tal94-demand-v2']);
            $this->fail('A connection failure did not throw.');
        } catch (SchedulingSolverTransportException $exception) {
            $this->assertSame(SchedulingSolverTransportException::ClassificationConnection, $exception->classification());
            $this->assertTrue($exception->isRetryable());
            $this->assertNull($exception->statusCode());
        }

        Http::assertSentCount(1);

        try {
            $this->localClient()->solve(['contract_version' => 'tal94-demand-v2']);
            $this->fail('A malformed solver response did not throw.');
        } catch (SchedulingSolverTransportException $exception) {
            $this->assertSame(SchedulingSolverTransportException::ClassificationMalformedResponse, $exception->classification());
            $this->assertFalse($exception->isRetryable());
        }

        Http::assertSentCount(2);
    }

    public function test_cloud_run_credential_failure_is_permanent_and_redacted(): void
    {
        $tokenProvider = new class implements CloudRunIdTokenProvider
        {
            public function tokenFor(string $audience): string
            {
                throw new RuntimeException('C:\\private\\scheduler-invoker.json contains invalid credentials');
            }
        };

        Http::preventStrayRequests();
        $client = new CloudRunSchedulingSolverClient(
            idTokenProvider: $tokenProvider,
            baseUrl: 'https://solver.example.test',
            audience: 'https://solver.example.test',
            timeoutSeconds: 30,
            connectTimeoutSeconds: 2,
        );

        try {
            $client->solve(['contract_version' => 'tal94-demand-v2']);
            $this->fail('A credential failure did not throw.');
        } catch (SchedulingSolverTransportException $exception) {
            $this->assertSame(SchedulingSolverTransportException::ClassificationCredential, $exception->classification());
            $this->assertFalse($exception->isRetryable());
            $this->assertStringNotContainsString('scheduler-invoker.json', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_cloud_run_probe_uses_health_endpoint_and_id_token(): void
    {
        $tokenProvider = new class implements CloudRunIdTokenProvider
        {
            public function tokenFor(string $audience): string
            {
                return 'unit-id-token-for-'.$audience;
            }
        };

        Http::preventStrayRequests();
        Http::fake([
            'https://solver.example.test/health' => Http::response(['status' => 'ok']),
        ]);

        $client = new CloudRunSchedulingSolverClient(
            idTokenProvider: $tokenProvider,
            baseUrl: 'https://solver.example.test',
            audience: 'https://solver.example.test',
            timeoutSeconds: 30,
            connectTimeoutSeconds: 2,
        );

        $this->assertSame(200, $client->probe()['status']);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://solver.example.test/health'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer unit-id-token-for-https://solver.example.test'));
        Http::assertSentCount(1);
    }

    public function test_scheduler_configuration_has_no_toggle_that_can_disable_cloud_run_iam(): void
    {
        $this->assertNull(config('tala_integrations.scheduling_solver.auth'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidLocalUrls(): array
    {
        return [
            'remote HTTPS' => ['https://solver.example.test'],
            'loopback HTTPS' => ['https://127.0.0.1:8080'],
            'private network host' => ['http://192.168.1.10:8080'],
            'nearby loopback address' => ['http://127.0.0.2:8080'],
            'credential-bearing URL' => ['http://user:secret@127.0.0.1:8080'],
            'path-bearing URL' => ['http://127.0.0.1:8080/api'],
            'query-bearing URL' => ['http://127.0.0.1:8080?debug=1'],
            'fragment-bearing URL' => ['http://127.0.0.1:8080#debug'],
        ];
    }

    /**
     * @return array<string, array{int, string, bool}>
     */
    public static function httpFailureCases(): array
    {
        return [
            'request timeout' => [408, SchedulingSolverTransportException::ClassificationTimeout, true],
            'rate limited' => [429, SchedulingSolverTransportException::ClassificationRateLimited, true],
            'service unavailable' => [503, SchedulingSolverTransportException::ClassificationServerError, true],
            'bad request' => [400, SchedulingSolverTransportException::ClassificationClientError, false],
            'unauthorized' => [401, SchedulingSolverTransportException::ClassificationClientError, false],
            'unprocessable response' => [422, SchedulingSolverTransportException::ClassificationClientError, false],
        ];
    }

    private function localClient(): LocalHttpSchedulingSolverClient
    {
        return new LocalHttpSchedulingSolverClient(
            baseUrl: 'http://127.0.0.1:8080',
            timeoutSeconds: 30,
            connectTimeoutSeconds: 2,
            environment: 'testing',
        );
    }
}
