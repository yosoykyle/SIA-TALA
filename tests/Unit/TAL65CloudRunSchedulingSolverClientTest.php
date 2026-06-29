<?php

namespace Tests\Unit;

use App\Actions\Integrations\SchedulingSolver\CloudRunIdTokenProvider;
use App\Actions\Integrations\SchedulingSolver\CloudRunSchedulingSolverClient;
use App\Actions\Integrations\SchedulingSolver\SchedulingSolverClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TAL65CloudRunSchedulingSolverClientTest extends TestCase
{
    public function test_cloud_run_driver_posts_tal61_snapshot_with_configured_id_token_auth(): void
    {
        $tokenProvider = new class implements CloudRunIdTokenProvider
        {
            /**
             * @var list<string>
             */
            public array $audiences = [];

            public function tokenFor(string $audience): string
            {
                $this->audiences[] = $audience;

                return 'unit-id-token';
            }
        };

        config()->set('tala_integrations.scheduling_solver.driver', 'cloud_run');
        config()->set('tala_integrations.scheduling_solver.url', 'https://solver.example.test');
        config()->set('tala_integrations.scheduling_solver.audience', 'https://solver.example.test');
        config()->set('tala_integrations.scheduling_solver.timeout_seconds', 123);
        config()->set('tala_integrations.scheduling_solver.connect_timeout_seconds', 7);

        $this->app->forgetInstance(SchedulingSolverClient::class);
        $this->app->forgetInstance(CloudRunSchedulingSolverClient::class);
        $this->app->instance(CloudRunIdTokenProvider::class, $tokenProvider);

        Http::preventStrayRequests();
        Http::fake([
            'https://solver.example.test/solve' => Http::response([
                'solver_run_id' => 55,
                'solver_status' => 'optimal',
                'candidate_schedule_id' => 'tal65-unit',
                'assignments' => [[
                    'scheduling_demand_id' => 501,
                    'assignment_status' => 'ok',
                ]],
                'runtime_seconds' => 0.25,
                'solver_version' => 'cloud-run-tal63',
            ]),
        ]);

        $client = app(SchedulingSolverClient::class);
        $snapshot = [
            'contract_version' => 'tal61-demand-v1',
            'run_metadata' => [
                'solver_run_id' => 55,
                'term_id' => 9,
            ],
            'scheduling_demands' => [[
                'scheduling_demand_id' => 501,
                'demand_key' => 'term-offering:1:delivery-group:2:component:3',
            ]],
            'optimization_settings' => [
                'candidate_schedule_mode' => 'provisional_only',
                'publish_after_solver' => false,
            ],
        ];

        $result = $client->solve($snapshot);

        $this->assertInstanceOf(CloudRunSchedulingSolverClient::class, $client);
        $this->assertSame('optimal', $result['solver_status']);
        $this->assertSame(['https://solver.example.test'], $tokenProvider->audiences);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://solver.example.test/solve'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer unit-id-token')
            && $request['contract_version'] === 'tal61-demand-v1'
            && $request['scheduling_demands'][0]['scheduling_demand_id'] === 501
            && $request['optimization_settings']['publish_after_solver'] === false);
        Http::assertSentCount(1);
    }
}
