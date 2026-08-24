<?php

namespace Tests\Feature\SystemAdministration;

use App\Actions\Enrollment\StudentUnitLoadPolicy;
use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Actions\SystemAdministration\OperationalEvidenceRecorder;
use App\Actions\SystemAdministration\SystemHealthPresenter;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\SystemHealth;
use App\Mail\TestConnectionMail;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\SystemSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SystemHealthAndGovernanceJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach (User::staffRoleNames() as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function canonical_system_administration_pages_are_registered_and_role_scoped(): void
    {
        $this->assertTrue(class_exists(SystemHealth::class));
        $this->assertTrue(class_exists(GovernanceAudit::class));
        $this->assertContains(SystemHealth::class, Filament::getPanel('admin')->getPages());
        $this->assertContains(GovernanceAudit::class, Filament::getPanel('admin')->getPages());

        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SystemHealth::class)->assertOk();
        Livewire::test(GovernanceAudit::class)->assertOk();

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
        ] as $deniedRole) {
            $this->actingAs($this->staff($deniedRole));

            $this->assertFalse(SystemHealth::canAccess());
            $this->assertFalse(GovernanceAudit::canAccess());
        }
    }

    #[Test]
    public function operational_evidence_commands_are_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('tala:operations:record-backup-evidence', $commands);
        $this->assertArrayHasKey('tala:operations:record-restore-evidence', $commands);
        $this->assertArrayNotHasKey('integrations:verify-mail-connection', $commands);
    }

    #[Test]
    public function system_health_projects_only_canonical_statuses_and_keeps_external_facts_unknown(): void
    {
        Config::set('mail.default', 'array');
        Config::set('tala_integrations.payments.driver', 'paymongo');
        Config::set('tala_integrations.payments.paymongo.public_key', 'pk_test_must_not_render');
        Config::set('tala_integrations.payments.paymongo.secret_key', 'sk_test_must_not_render');
        Config::set('tala_integrations.payments.paymongo.webhook_signature', 'whsec_must_not_render');

        $capture = app(SystemHealthPresenter::class)->capture();
        $rows = collect($capture['rows']);

        $this->assertSame([], $rows->pluck('status')->unique()->diff([
            SystemHealthPresenter::Available,
            SystemHealthPresenter::Attention,
            SystemHealthPresenter::Unavailable,
            SystemHealthPresenter::Unknown,
        ])->values()->all());
        $this->assertSame(
            ['Unknown'],
            $rows->only(['primary-host-backups', 'provider-dashboards', 'independent-provider', 'physical-custody'])->pluck('status')->unique()->values()->all(),
        );
        $this->assertTrue($rows->every(fn (array $row): bool => filled($row['evidence']) && filled($row['as_of']) && filled($row['next_action'])));

        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $html = Livewire::test(SystemHealth::class)->html();

        $this->assertStringContainsString('Not checked by TALA', $html);
        $this->assertStringContainsString('planning target, not achieved evidence', $html);
        $this->assertStringNotContainsString('pk_test_must_not_render', $html);
        $this->assertStringNotContainsString('sk_test_must_not_render', $html);
        $this->assertStringNotContainsString('whsec_must_not_render', $html);
    }

    #[Test]
    public function mail_health_distinguishes_pending_successful_and_failed_local_evidence(): void
    {
        Config::set('mail.default', 'array');
        $event = OperationalEvent::factory()->create([
            'event_domain' => OperationalEvent::DomainNotifications,
            'integration' => OperationalEvent::IntegrationMail,
            'status' => OperationalEvent::StatusPending,
            'occurred_at' => now(),
        ]);
        $presenter = app(SystemHealthPresenter::class);

        $this->assertSame(SystemHealthPresenter::Attention, $presenter->capture()['rows']['mail']['status']);

        $event->update(['status' => OperationalEvent::StatusProcessed]);
        $this->assertSame(SystemHealthPresenter::Available, $presenter->capture()['rows']['mail']['status']);

        $event->update(['status' => OperationalEvent::StatusFailed]);
        $this->assertSame(SystemHealthPresenter::Attention, $presenter->capture()['rows']['mail']['status']);
    }

    #[Test]
    public function failed_refresh_retains_the_preceding_capture_and_marks_it_stale(): void
    {
        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(SystemHealth::class);
        $precedingCapture = $component->get('capture');

        app()->instance(SystemHealthPresenter::class, new class extends SystemHealthPresenter
        {
            public function capture(): array
            {
                throw new RuntimeException('must-not-render');
            }
        });

        $component
            ->callAction('refreshLocalEvidence')
            ->assertSet('capture', $precedingCapture)
            ->assertSet('captureStale', true)
            ->assertSee('preceding capture was retained')
            ->assertDontSee('must-not-render');
    }

    #[Test]
    public function mail_self_test_is_self_only_throttled_and_records_safe_classification(): void
    {
        Mail::fake();
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $key = 'tala:system-health:mail-self-test:'.$admin->getKey();
        RateLimiter::clear($key);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(SystemHealth::class);
        $component->callAction('sendTestEmail')->assertNotified();
        $component->callAction('sendTestEmail')->assertNotified();

        Mail::assertSentCount(1);
        Mail::assertSent(TestConnectionMail::class, fn (TestConnectionMail $mail): bool => $mail->hasTo($admin->email));

        $event = OperationalEvent::query()
            ->where('event_type', 'mail_self_test_accepted')
            ->where('user_id', $admin->getKey())
            ->sole();

        $this->assertSame(OperationalEvent::StatusProcessed, $event->status);
        $this->assertNull($event->recipient_snapshot);
        $this->assertNull($event->diagnostics);
        $this->assertNull($event->payload);
    }

    #[Test]
    public function failed_mail_self_test_records_only_safe_failure_classification(): void
    {
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $key = 'tala:system-health:mail-self-test:'.$admin->getKey();
        RateLimiter::clear($key);
        Mail::shouldReceive('to')
            ->once()
            ->with($admin->email)
            ->andThrow(new RuntimeException('provider-secret-must-not-render'));

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::test(SystemHealth::class)
            ->callAction('sendTestEmail')
            ->assertNotified('Mail self-test failed')
            ->assertDontSee('provider-secret-must-not-render');

        $event = OperationalEvent::query()
            ->where('event_type', 'mail_self_test_failed')
            ->where('user_id', $admin->getKey())
            ->sole();

        $this->assertSame(OperationalEvent::StatusFailed, $event->status);
        $this->assertNull($event->recipient_snapshot);
        $this->assertNull($event->diagnostics);
        $this->assertNull($event->payload);
        $this->assertStringNotContainsString('provider-secret-must-not-render', $component->html());
    }

    #[Test]
    public function evidence_is_validated_idempotent_immutable_correctable_and_order_safe(): void
    {
        $recorder = app(OperationalEvidenceRecorder::class);
        $current = $this->backupEvidence('backup-current', now()->subMinutes(5));
        $recorded = $recorder->record(OperationalEvidenceRecorder::TypeBackup, json_encode($current, JSON_THROW_ON_ERROR));
        $identical = $recorder->record(OperationalEvidenceRecorder::TypeBackup, json_encode($current, JSON_THROW_ON_ERROR));

        $this->assertTrue($recorded['created']);
        $this->assertFalse($identical['created']);
        $this->assertSame($recorded['event']->getKey(), $identical['event']->getKey());

        try {
            $conflict = [...$current, 'outcome' => 'FAILED'];
            $recorder->record(OperationalEvidenceRecorder::TypeBackup, json_encode($conflict, JSON_THROW_ON_ERROR));
            $this->fail('Conflicting evidence must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('external_reference', $exception->errors());
        }

        $failed = [...$this->backupEvidence('backup-failed', now()->subMinutes(4)),
            'outcome' => 'FAILED',
            'integrity_result' => 'FAILED',
            'manifest_result' => 'FAILED',
        ];
        $failedEvent = $recorder->record(OperationalEvidenceRecorder::TypeBackup, json_encode($failed, JSON_THROW_ON_ERROR))['event'];
        $corrected = [...$this->backupEvidence('backup-corrected', now()->subMinutes(3)),
            'supersedes_external_reference' => 'backup-failed',
        ];
        $correctedEvent = $recorder->record(OperationalEvidenceRecorder::TypeBackup, json_encode($corrected, JSON_THROW_ON_ERROR))['event'];

        $this->assertSame($failedEvent->getKey(), $correctedEvent->related_record_id);
        $this->assertSame(OperationalEvent::class, $correctedEvent->related_record_type);
        $this->assertDatabaseHas('operational_events', ['id' => $failedEvent->getKey()]);

        $olderDelayed = $this->backupEvidence('backup-delayed', now()->subDay());
        $recorder->record(OperationalEvidenceRecorder::TypeBackup, json_encode($olderDelayed, JSON_THROW_ON_ERROR));
        $latest = OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainOperations)
            ->where('integration', OperationalEvent::IntegrationBackup)
            ->latest('occurred_at')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('operations:backup:backup-corrected', $latest->external_id);

        $unsafe = [...$current, 'external_reference' => 'C:\\private\\backup.json'];
        $this->expectException(ValidationException::class);
        $recorder->record(OperationalEvidenceRecorder::TypeBackup, json_encode($unsafe, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function backup_health_distinguishes_missing_current_overdue_and_failed_evidence(): void
    {
        Config::set('tala_operations.backup_overdue_after_hours');
        $presenter = app(SystemHealthPresenter::class);

        $this->assertSame(SystemHealthPresenter::Unknown, $presenter->capture()['rows']['backup']['status']);

        app(OperationalEvidenceRecorder::class)->record(
            OperationalEvidenceRecorder::TypeBackup,
            json_encode($this->backupEvidence('backup-current-health', now()->subHours(2)), JSON_THROW_ON_ERROR),
        );
        $this->assertSame(SystemHealthPresenter::Available, $presenter->capture()['rows']['backup']['status']);

        Config::set('tala_operations.backup_overdue_after_hours', 1);
        $this->assertSame(SystemHealthPresenter::Attention, $presenter->capture()['rows']['backup']['status']);
        $this->assertStringContainsString('older than', $presenter->capture()['rows']['backup']['evidence']);

        $failed = [
            ...$this->backupEvidence('backup-failed-health', now()->subMinute()),
            'outcome' => 'FAILED',
            'integrity_result' => 'FAILED',
            'manifest_result' => 'FAILED',
        ];
        app(OperationalEvidenceRecorder::class)->record(
            OperationalEvidenceRecorder::TypeBackup,
            json_encode($failed, JSON_THROW_ON_ERROR),
        );

        $capture = $presenter->capture()['rows']['backup'];
        $this->assertSame(SystemHealthPresenter::Attention, $capture['status']);
        $this->assertStringContainsString('failed or requires reconciliation', $capture['evidence']);
        $this->assertStringContainsString('preceding successful generation remains recorded', $capture['evidence']);
    }

    #[Test]
    public function restore_evidence_requires_complete_reconciliation_without_claiming_production_recovery(): void
    {
        $recorder = app(OperationalEvidenceRecorder::class);
        $result = $recorder->record(
            OperationalEvidenceRecorder::TypeRestore,
            json_encode($this->restoreEvidence('restore-drill-1', now()->subMinute()), JSON_THROW_ON_ERROR),
        );

        $this->assertTrue($result['created']);
        $this->assertSame(OperationalEvent::StatusProcessed, $result['event']->status);
        $this->assertSame(42, data_get($result['event']->payload, 'measured_duration_minutes'));
        $this->assertSame(0, data_get($result['event']->payload, 'observed_data_loss_minutes'));
        $this->assertStringNotContainsString('production recovery achieved', json_encode($result['event']->payload, JSON_THROW_ON_ERROR));

        $degraded = [
            ...$this->restoreEvidence('restore-drill-degraded', now()->subMinute()),
            'queue_integration_result' => 'DEGRADED',
        ];
        $degradedEvent = $recorder->record(
            OperationalEvidenceRecorder::TypeRestore,
            json_encode($degraded, JSON_THROW_ON_ERROR),
        )['event'];
        $this->assertSame(OperationalEvent::StatusReviewRequired, $degradedEvent->status);

        $failed = [
            ...$this->restoreEvidence('restore-drill-invalid-success', now()->subMinute()),
            'queue_integration_result' => 'FAILED',
        ];

        try {
            $recorder->record(
                OperationalEvidenceRecorder::TypeRestore,
                json_encode($failed, JSON_THROW_ON_ERROR),
            );
            $this->fail('A successful restore claim cannot contain failed reconciliation evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('queue_integration_result', $exception->errors());
        }
    }

    #[Test]
    public function evidence_commands_accept_one_safe_local_json_file_and_reject_unknown_fields(): void
    {
        $directory = storage_path('framework/testing/operations-evidence');
        File::ensureDirectoryExists($directory);
        $validPath = $directory.'/backup-valid.json';
        $restorePath = $directory.'/restore-valid.json';
        $invalidPath = $directory.'/backup-invalid.json';

        try {
            File::put($validPath, json_encode($this->backupEvidence('backup-command', now()->subMinute()), JSON_THROW_ON_ERROR));
            File::put($restorePath, json_encode($this->restoreEvidence('restore-command', now()->subMinute()), JSON_THROW_ON_ERROR));
            File::put($invalidPath, json_encode([...$this->backupEvidence('backup-unsafe', now()->subMinute()), 'private_path' => 'must-not-be-accepted'], JSON_THROW_ON_ERROR));

            $this->assertSame(Command::SUCCESS, Artisan::call('tala:operations:record-backup-evidence', ['--input' => $validPath]));
            $this->assertStringContainsString('Backup evidence recorded', Artisan::output());
            $this->assertSame(Command::SUCCESS, Artisan::call('tala:operations:record-restore-evidence', ['--input' => $restorePath]));
            $this->assertStringContainsString('Restore evidence recorded', Artisan::output());
            $this->assertSame(Command::FAILURE, Artisan::call('tala:operations:record-backup-evidence', ['--input' => $invalidPath]));
            $this->assertStringContainsString('Unknown evidence fields', Artisan::output());
            $this->assertStringNotContainsString($invalidPath, Artisan::output());
        } finally {
            File::delete([$validPath, $restorePath, $invalidPath]);
        }
    }

    #[Test]
    public function governance_tabs_project_direct_allowlisted_evidence_without_private_fields(): void
    {
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'must-not-render-description',
            'event' => 'updated',
            'causer_type' => User::class,
            'causer_id' => $admin->getKey(),
            'properties' => json_encode(['secret' => 'activity-secret-must-not-render'], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);
        DB::table('activity_log')->insert([
            'log_name' => 'authentication',
            'description' => 'login failed',
            'event' => 'login_failed',
            'causer_type' => User::class,
            'causer_id' => $admin->getKey(),
            'properties' => json_encode(['attempted_identifier' => 'private@example.test'], JSON_THROW_ON_ERROR),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        OperationalEvent::factory()->create([
            'event_type' => 'safe_fixture_event',
            'user_id' => $admin->getKey(),
            'payload' => ['token' => 'operational-secret-must-not-render'],
            'diagnostics' => ['reason' => 'diagnostic-must-not-render'],
            'recipient_snapshot' => ['email' => 'recipient-must-not-render@example.test'],
            'occurred_at' => now()->subMinute(),
        ]);
        OutputAccessLog::query()->create([
            'output_type' => 'TOR',
            'source_record_type' => 'transcript_request',
            'source_record_id' => 1,
            'actor_user_id' => $admin->getKey(),
            'actor_role' => User::StaffRoleSystemSuperAdmin,
            'action' => 'VIEW',
            'request_context' => ['ip' => '203.0.113.99', 'token' => 'request-secret-must-not-render'],
            'stored_file_reference' => 'private/path/must-not-render.pdf',
            'status' => 'VIEWED',
            'occurred_at' => now(),
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::test(GovernanceAudit::class);

        $this->assertSame(array_keys(GovernanceEvidenceProjection::tabs()), array_keys($component->get('tabs')));
        $component->assertSee('Institutional Changes')->assertSee('Updated');
        $this->assertSafeGovernanceHtml($component->html());

        $component->call('setActiveTab', GovernanceEvidenceProjection::SystemEvents)->assertSee('Safe Fixture Event')->assertSee('Login Failed');
        $this->assertSafeGovernanceHtml($component->html());

        $component->call('setActiveTab', GovernanceEvidenceProjection::OutputAccess)->assertSee('TOR')->assertSee('Output access');
        $this->assertSafeGovernanceHtml($component->html());

        $component->call('setActiveTab', GovernanceEvidenceProjection::PrivacyRetention)
            ->assertSee('Automatic retention disposal: Not provided in this MVP')
            ->assertSee('External compliance status: Not evaluated by TALA');
    }

    #[Test]
    public function governance_projection_filters_and_orders_direct_evidence_deterministically(): void
    {
        $first = $this->staff(User::StaffRoleSystemSuperAdmin);
        $second = $this->staff(User::StaffRoleSystemSuperAdmin);
        $first->forceFill([
            'first_name' => 'Alpha',
            'middle_name' => null,
            'last_name' => 'Administrator',
        ])->save();
        $second->forceFill([
            'first_name' => 'Beta',
            'middle_name' => null,
            'last_name' => 'Administrator',
        ])->save();

        foreach ([
            [$first, 'created', now()->subMinutes(2)],
            [$second, 'updated', now()->subMinute()],
        ] as [$actor, $event, $occurredAt]) {
            DB::table('activity_log')->insert([
                'log_name' => 'default',
                'description' => 'private detail',
                'event' => $event,
                'causer_type' => User::class,
                'causer_id' => $actor->getKey(),
                'properties' => '{}',
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);
        }

        $projection = app(GovernanceEvidenceProjection::class);
        $all = $projection->paginate(GovernanceEvidenceProjection::InstitutionalChanges, 1, 25, null, []);
        $recordedDate = (string) DB::table('activity_log')
            ->where('causer_id', $first->getKey())
            ->selectRaw('DATE(created_at) AS recorded_date')
            ->value('recorded_date');
        $filtered = $projection->paginate(GovernanceEvidenceProjection::InstitutionalChanges, 1, 25, 'Alpha', [
            'actor' => ['value' => $first->getKey()],
            'type' => ['value' => 'created'],
            'date' => [
                'from' => $recordedDate,
                'until' => $recordedDate,
            ],
        ]);

        $allItems = array_values($all->items());
        $filteredItems = array_values($filtered->items());

        $this->assertSame($second->getKey(), $allItems[0]['actor_id']);
        $this->assertCount(1, $filteredItems);
        $this->assertSame($first->getKey(), $filteredItems[0]['actor_id']);
        $this->assertSame('Created', $filteredItems[0]['type']);
    }

    #[Test]
    public function unit_load_setting_consumer_remains_active_without_a_system_settings_resource(): void
    {
        SystemSetting::query()->forceCreate([
            'key' => StudentUnitLoadPolicy::SettingKey,
            'scope_type' => 'institution',
            'scope_id' => 0,
            'value_type' => SystemSetting::ValueTypeJson,
            'value' => json_encode([
                'fallback_normal_max_units' => 19,
                'regular_overload_excess_cap' => 4,
                'summer_overload_excess_cap' => 3,
                'default_approving_authority' => 'Academic Head',
                'default_recording_office' => 'Registrar',
            ], JSON_THROW_ON_ERROR),
            'effective_from' => now(),
            'version' => 1,
            'status' => 'active',
        ]);

        $policy = app(StudentUnitLoadPolicy::class);

        $this->assertSame(19.0, $policy->fallbackNormalMaxUnits());
        $this->assertSame(23.0, $policy->configuredCapFor(19.0, null));
        $this->assertNotContains('SystemSettingResource', array_map(class_basename(...), Filament::getPanel('admin')->getResources()));
    }

    /** @return array<string, mixed> */
    private function backupEvidence(string $reference, \DateTimeInterface $completedAt): array
    {
        $completed = now()->setTimestamp($completedAt->getTimestamp());

        return [
            'schema_version' => '1',
            'external_reference' => $reference,
            'outcome' => 'SUCCEEDED',
            'started_at' => $completed->copy()->subMinutes(2)->toRfc3339String(),
            'completed_at' => $completed->toRfc3339String(),
            'application_revision' => 'a06ad54a',
            'migration_result' => 'MATCHED',
            'integrity_result' => 'PASSED',
            'operator_reference' => 'job-runner-1',
            'generation_reference' => 'generation-'.$reference,
            'database_export_result' => 'PASSED',
            'private_files_result' => 'PASSED',
            'manifest_result' => 'PASSED',
            'off_host_result' => 'PASSED',
        ];
    }

    /** @return array<string, mixed> */
    private function restoreEvidence(string $reference, \DateTimeInterface $completedAt): array
    {
        $completed = now()->setTimestamp($completedAt->getTimestamp());

        return [
            'schema_version' => '1',
            'external_reference' => $reference,
            'outcome' => 'SUCCEEDED',
            'started_at' => $completed->copy()->subMinutes(42)->toRfc3339String(),
            'completed_at' => $completed->toRfc3339String(),
            'application_revision' => 'a06ad54a',
            'migration_result' => 'MATCHED',
            'integrity_result' => 'PASSED',
            'operator_reference' => 'restore-operator-1',
            'generation_reference' => 'generation-backup-current',
            'measured_duration_minutes' => 42,
            'observed_data_loss_minutes' => 0,
            'manifest_result' => 'PASSED',
            'database_restore_result' => 'PASSED',
            'private_files_restore_result' => 'PASSED',
            'authentication_result' => 'PASSED',
            'critical_journeys_result' => 'PASSED',
            'session_cache_result' => 'PASSED',
            'queue_integration_result' => 'PASSED',
            'lawful_disposition_result' => 'NOT_APPLICABLE',
        ];
    }

    private function assertSafeGovernanceHtml(string $html): void
    {
        foreach ([
            'must-not-render-description',
            'activity-secret-must-not-render',
            'private@example.test',
            'operational-secret-must-not-render',
            'diagnostic-must-not-render',
            'recipient-must-not-render@example.test',
            '203.0.113.99',
            'request-secret-must-not-render',
            'private/path/must-not-render.pdf',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $html);
        }
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
