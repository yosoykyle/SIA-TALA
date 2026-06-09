<?php

namespace Tests\Feature;

use App\Actions\Integrations\SchedulingSolver\CloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\CloudRunSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\GoogleServiceAccountCloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\LocalStubSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SchedulingCloudRunSolverClientTest extends TestCase
{
    public function test_cloud_run_solver_client_sends_google_id_token_to_solve_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://solver.test/solve' => Http::response([
                'solver_status' => 'feasible',
                'assigned_count' => 1,
                'draft_rows' => [],
            ]),
        ]);

        $client = new CloudRunSchedulingSolverClient(
            idTokenProvider: new FakeCloudRunIdTokenProvider('test-id-token'),
            baseUrl: 'https://solver.test',
            audience: 'https://solver.test',
            timeoutSeconds: 300,
            connectTimeoutSeconds: 10,
        );

        $result = $client->solve([
            'run_id' => 10,
            'term_id' => 20,
        ]);

        $this->assertSame('feasible', $result['solver_status']);
        $this->assertSame(1, $result['assigned_count']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://solver.test/solve'
                && $request->hasHeader('Authorization', 'Bearer test-id-token')
                && $request['run_id'] === 10
                && $request['term_id'] === 20;
        });
    }

    public function test_cloud_run_solver_client_can_probe_private_service(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://solver.test' => Http::response('Hello from Cloud Run', 200),
        ]);

        $client = new CloudRunSchedulingSolverClient(
            idTokenProvider: new FakeCloudRunIdTokenProvider('probe-id-token'),
            baseUrl: 'https://solver.test',
            audience: 'https://solver.test',
            timeoutSeconds: 300,
            connectTimeoutSeconds: 10,
        );

        $result = $client->probe();

        $this->assertSame(200, $result['status']);
        $this->assertSame('Hello from Cloud Run', $result['body']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://solver.test'
                && $request->hasHeader('Authorization', 'Bearer probe-id-token');
        });
    }

    public function test_cloud_run_solver_client_requires_url_before_minting_token(): void
    {
        $tokenProvider = new FakeCloudRunIdTokenProvider('unused');

        $client = new CloudRunSchedulingSolverClient(
            idTokenProvider: $tokenProvider,
            baseUrl: null,
            audience: 'https://solver.test',
            timeoutSeconds: 300,
            connectTimeoutSeconds: 10,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scheduling solver URL is not configured.');

        $client->solve(['run_id' => 10]);

        $this->assertSame(0, $tokenProvider->calls);
    }

    public function test_google_service_account_token_provider_requires_credentials_path(): void
    {
        $provider = new GoogleServiceAccountCloudRunIdTokenProvider(credentialsPath: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scheduling solver credentials path is not configured.');

        $provider->tokenFor('https://solver.test');
    }

    public function test_scheduling_solver_binding_defaults_to_local_stub(): void
    {
        config([
            'tala_integrations.scheduling_solver.driver' => 'local_stub',
        ]);
        $this->app->forgetInstance(SchedulingSolverClient::class);

        $client = app(SchedulingSolverClient::class);

        $this->assertInstanceOf(LocalStubSchedulingSolverClient::class, $client);
        $this->assertSame('local_stub', $client->solve([])['solver_status']);
    }

    public function test_scheduling_solver_binding_resolves_cloud_run_driver(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://solver.test/solve' => Http::response(['solver_status' => 'ok']),
        ]);

        config([
            'tala_integrations.scheduling_solver.driver' => 'cloud_run',
            'tala_integrations.scheduling_solver.url' => 'https://solver.test',
            'tala_integrations.scheduling_solver.audience' => 'https://solver.test',
            'tala_integrations.scheduling_solver.timeout_seconds' => 300,
            'tala_integrations.scheduling_solver.connect_timeout_seconds' => 10,
        ]);

        $this->app->instance(CloudRunIdTokenProvider::class, new FakeCloudRunIdTokenProvider('bound-token'));
        $this->app->forgetInstance(CloudRunSchedulingSolverClient::class);
        $this->app->forgetInstance(SchedulingSolverClient::class);

        $client = app(SchedulingSolverClient::class);

        $this->assertInstanceOf(CloudRunSchedulingSolverClient::class, $client);
        $this->assertSame('ok', $client->solve(['run_id' => 99])['solver_status']);
    }
}

final class FakeCloudRunIdTokenProvider implements CloudRunIdTokenProvider
{
    public int $calls = 0;

    public function __construct(private readonly string $token) {}

    public function tokenFor(string $audience): string
    {
        $this->calls++;

        return $this->token;
    }
}
