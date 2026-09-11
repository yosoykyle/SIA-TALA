<?php

namespace Tests\Feature\SystemAdministration;

use App\Actions\Finance\FinanceEvidenceService;
use App\Actions\SystemAdministration\GovernanceEvidenceProjection;
use App\Actions\SystemAdministration\OperationalEvidenceRecorder;
use App\Actions\SystemAdministration\SystemHealthPresenter;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\SystemHealth;
use App\Mail\TestConnectionMail;
use App\Models\AdmissionCycle;
use App\Models\CompletionReadinessVersion;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\ScheduleGenerationRun;
use App\Models\SystemSetting;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FAQRCode\Google2FA;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Browser\BrowserQualificationEnvironment;
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

        foreach (array_merge(User::staffRoleNames(), ['applicant', 'student']) as $role) {
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
            [SystemHealthPresenter::NotRecentlyChecked],
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
        OperationalEvent::query()->where('integration', OperationalEvent::IntegrationBackup)->delete();
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
            'subject_type' => User::class,
            'subject_id' => $admin->getKey(),
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
    public function output_access_evidence_with_lowercase_generated_status_normalizes_to_recorded(): void
    {
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $log = OutputAccessLog::query()->create([
            'output_type' => 'certificate_of_registration',
            'source_record_type' => 'registration_case',
            'source_record_id' => 1,
            'actor_user_id' => $admin->getKey(),
            'actor_role' => User::StaffRoleSystemSuperAdmin,
            'action' => 'PRINT',
            'request_context' => ['channel' => 'web'],
            'stored_file_reference' => 'cor_001.pdf',
            'status' => 'generated',
            'occurred_at' => now(),
        ]);

        $projection = app(GovernanceEvidenceProjection::class);
        $paginated = $projection->paginate(GovernanceEvidenceProjection::OutputAccess, 1, 25, null, []);
        $items = collect($paginated->items());

        $logItem = $items->firstWhere('reference_id', 'output:'.$log->getKey());
        $this->assertNotNull($logItem);
        $this->assertSame('Recorded', $logItem['status']);
        $this->assertNotSame('Attention', $logItem['status']);
        $this->assertSame('Certificate Of Registration', $logItem['type']);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(GovernanceAudit::class)
            ->call('setActiveTab', GovernanceEvidenceProjection::OutputAccess)
            ->assertSee('Certificate Of Registration')
            ->assertSee('Recorded');
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
                'subject_type' => User::class,
                'subject_id' => $actor->getKey(),
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
    public function explicit_allowlist_excludes_unknown_operational_and_activity_events(): void
    {
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);

        // Unknown activity and operational events
        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'unknown activity',
            'event' => 'uncataloged_custom_activity',
            'causer_type' => User::class,
            'causer_id' => $admin->getKey(),
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unknownOperational = OperationalEvent::factory()->create([
            'event_domain' => OperationalEvent::DomainOperations,
            'event_type' => 'uncataloged_operational_event',
            'user_id' => $admin->getKey(),
            'occurred_at' => now(),
        ]);

        // Known allowlisted operational events
        $knownMailSelfTest = OperationalEvent::factory()->create([
            'event_domain' => OperationalEvent::DomainNotifications,
            'event_type' => 'mail_self_test_accepted',
            'user_id' => $admin->getKey(),
            'occurred_at' => now(),
        ]);

        $knownBackup = OperationalEvent::factory()->create([
            'event_domain' => OperationalEvent::DomainOperations,
            'event_type' => 'backup_completed',
            'user_id' => $admin->getKey(),
            'occurred_at' => now(),
        ]);

        $projection = app(GovernanceEvidenceProjection::class);

        // Verify Institutional Changes excludes unknown activity
        $institutionalPage = $projection->paginate(GovernanceEvidenceProjection::InstitutionalChanges, 1, 50, null, []);
        $institutionalTypes = collect($institutionalPage->items())->pluck('type')->all();
        $this->assertNotContains('Uncataloged Custom Activity', $institutionalTypes);

        // Verify System Events excludes unknown operational events
        $systemEventsPage = $projection->paginate(GovernanceEvidenceProjection::SystemEvents, 1, 50, null, []);
        $systemEventReferences = collect($systemEventsPage->items())->pluck('reference_id')->all();
        $this->assertNotContains('operational:'.$unknownOperational->getKey(), $systemEventReferences);

        // Verify System Events includes known operational events
        $this->assertContains('operational:'.$knownMailSelfTest->getKey(), $systemEventReferences);
        $this->assertContains('operational:'.$knownBackup->getKey(), $systemEventReferences);
    }

    #[Test]
    public function retired_unit_load_setting_is_preserved_only_as_unreachable_history(): void
    {
        SystemSetting::query()->forceCreate([
            'key' => 'student_unit_load_policy_defaults',
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

        $this->assertNull(SystemSetting::definitionFor('student_unit_load_policy_defaults'));
        $this->assertDatabaseHas('system_settings', ['key' => 'student_unit_load_policy_defaults']);
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

    #[Test]
    public function governance_tab_navigation_has_accessible_wai_aria_attributes(): void
    {
        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $html = Livewire::test(GovernanceAudit::class)->html();

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('aria-label="Governance evidence views"', $html);
        $this->assertStringContainsString('role="tab"', $html);
        $this->assertStringContainsString('aria-controls="tabpanel-governance"', $html);
        $this->assertStringContainsString('aria-selected="true"', $html);
        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertStringContainsString('id="tabpanel-governance"', $html);
    }

    #[Test]
    public function governance_privacy_tab_contains_canonical_disposal_notice(): void
    {
        $this->actingAs($this->staff(User::StaffRoleSystemSuperAdmin));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(GovernanceAudit::class)
            ->call('setActiveTab', GovernanceEvidenceProjection::PrivacyRetention)
            ->assertSee("Automatic record disposal is not available in TALA. Follow the institution's approved privacy and records procedure.", escape: false);
    }

    #[Test]
    public function billing_slip_artifacts_and_routes_are_completely_retired(): void
    {
        $this->assertFalse(Route::has('finance.billing-slip'));
        $this->assertFalse(file_exists(app_path('Http/Controllers/BillingSlipController.php')));
        $this->assertFalse(file_exists(resource_path('views/finance/billing-slip.blade.php')));
        $this->assertFalse(method_exists(FinanceEvidenceService::class, 'billingSlip'));
    }

    #[Test]
    public function solver_queued_and_dispatching_states_map_to_needs_attention(): void
    {
        $term = Term::query()->first() ?? Term::factory()->create();
        $queuedRun = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusQueued,
            'updated_at' => now(),
        ]);

        $capture = app(SystemHealthPresenter::class)->capture();
        $solverRow = collect($capture['rows'])->firstWhere('key', 'solver');

        $this->assertNotNull($solverRow);
        $this->assertSame(SystemHealthPresenter::NeedsAttention, $solverRow['status']);
        $this->assertStringContainsString('queued or dispatching', $solverRow['evidence']);

        $queuedRun->update([
            'status' => ScheduleGenerationRun::StatusDispatching,
            'updated_at' => now(),
        ]);

        $captureDispatching = app(SystemHealthPresenter::class)->capture();
        $solverDispatchingRow = collect($captureDispatching['rows'])->firstWhere('key', 'solver');

        $this->assertNotNull($solverDispatchingRow);
        $this->assertSame(SystemHealthPresenter::NeedsAttention, $solverDispatchingRow['status']);
        $this->assertStringContainsString('queued or dispatching', $solverDispatchingRow['evidence']);
    }

    #[Test]
    public function governance_event_classification_strictly_scopes_events_and_excludes_read_events(): void
    {
        $projection = app(GovernanceEvidenceProjection::class);

        // 1. PayMongo events: explicit allowlist vs arbitrary paymongo_%
        $unsupportedPayMongoEvent = OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => 'webhook',
            'direction' => OperationalEvent::DirectionInbound,
            'event_type' => 'paymongo_arbitrary_unsupported_probe',
            'status' => OperationalEvent::StatusProcessed,
            'occurred_at' => now(),
        ]);

        $supportedPayMongoEvent = OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => 'webhook',
            'direction' => OperationalEvent::DirectionInbound,
            'event_type' => 'payment.paid',
            'status' => OperationalEvent::StatusProcessed,
            'occurred_at' => now(),
        ]);

        $systemEvents = $projection->query(GovernanceEvidenceProjection::SystemEvents)->get();
        $this->assertFalse($systemEvents->contains('reference_id', "operational:{$unsupportedPayMongoEvent->id}"));
        $this->assertTrue($systemEvents->contains('reference_id', "operational:{$supportedPayMongoEvent->id}"));

        // 2. payment_evidence_accessed excluded from institutional changes
        $admin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $paymentLog = activity()
            ->causedBy($admin)
            ->event('payment_evidence_accessed')
            ->log('Payment evidence was viewed');
        assert($paymentLog instanceof Model);

        $institutionalChanges = $projection->query(GovernanceEvidenceProjection::InstitutionalChanges)->get();
        $this->assertFalse($institutionalChanges->contains('type', 'payment_evidence_accessed'));
        $this->assertFalse($institutionalChanges->contains('reference_id', "activity:{$paymentLog->getKey()}"));

        $paginated = $projection->paginate(GovernanceEvidenceProjection::InstitutionalChanges, 1, 50, null, []);
        $items = collect($paginated->items());
        $this->assertFalse($items->contains('reference_id', "activity:{$paymentLog->getKey()}"));
        $this->assertFalse($items->pluck('type')->map(fn ($t) => strtolower((string) $t))->contains('payment evidence accessed'));

        // 3. Generic created/updated/deleted qualified by supported institutional models and causer attribution
        activity()
            ->causedBy($admin)
            ->performedOn($admin) // User is in supportedInstitutionalGenericSubjects
            ->event('created')
            ->log('User created');

        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => 'Unrelated subject created',
            'subject_type' => 'App\\Models\\UnrelatedDomainModel',
            'subject_id' => 9999,
            'causer_type' => User::class,
            'causer_id' => $admin->id,
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $anonymousSubjectlessId = DB::table('activity_log')->insertGetId([
            'log_name' => 'default',
            'description' => 'Anonymous subjectless created',
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => null,
            'causer_id' => null,
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $staffSubjectlessId = DB::table('activity_log')->insertGetId([
            'log_name' => 'default',
            'description' => 'Staff subjectless generic event',
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => User::class,
            'causer_id' => $admin->id,
            'event' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $institutional = $projection->query(GovernanceEvidenceProjection::InstitutionalChanges)->get();
        $this->assertTrue($institutional->contains(function ($row) {
            return strtolower($row->type) === 'created' && str_contains($row->affected_record, 'User #');
        }));
        $this->assertFalse($institutional->contains(function ($row) {
            return str_contains($row->affected_record, 'UnrelatedDomainModel');
        }));
        $this->assertFalse($institutional->contains('reference_id', "activity:{$anonymousSubjectlessId}"));
        $this->assertFalse($institutional->contains('reference_id', "activity:{$staffSubjectlessId}"));

        // 4. Domain-event sources strictly filter against supported event types
        $cycle = AdmissionCycle::factory()->create();
        $unsupportedCycleEventId = DB::table('admission_cycle_events')->insertGetId([
            'admission_cycle_id' => $cycle->id,
            'event_type' => 'unsupported_speculative_action',
            'event_key' => 'unsupported-cycle-key-'.fake()->uuid(),
            'authority_reference' => 'Synthetic Test Authority',
            'actor_id' => $admin->id,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supportedCycleEventId = DB::table('admission_cycle_events')->insertGetId([
            'admission_cycle_id' => $cycle->id,
            'event_type' => 'Published',
            'event_key' => 'supported-cycle-key-'.fake()->uuid(),
            'authority_reference' => 'Synthetic Test Authority',
            'actor_id' => $admin->id,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $institutionalAfterCycle = $projection->query(GovernanceEvidenceProjection::InstitutionalChanges)->get();
        $this->assertFalse($institutionalAfterCycle->contains('reference_id', "admission_cycle:{$unsupportedCycleEventId}"));
        $this->assertTrue($institutionalAfterCycle->contains('reference_id', "admission_cycle:{$supportedCycleEventId}"));
    }

    #[Test]
    public function output_projection_maps_stale_inaccessible_and_unclassified_to_attention_or_unknown_with_safe_attribution(): void
    {
        $projection = app(GovernanceEvidenceProjection::class);

        // 1. Stale maps to Attention (not Recorded)
        $staleLog = OutputAccessLog::query()->create([
            'output_type' => 'transcript_preview',
            'source_record_type' => 'TranscriptRequest',
            'source_record_id' => 101,
            'actor_role' => 'registrar',
            'action' => 'stale',
            'status' => 'stale',
            'occurred_at' => now(),
        ]);

        // 2. Inaccessible maps to Attention (not Recorded)
        $inaccessibleLog = OutputAccessLog::query()->create([
            'output_type' => 'timetable_preview',
            'source_record_type' => 'PublishedTimetableVersion',
            'source_record_id' => 102,
            'actor_role' => 'registrar',
            'action' => 'inaccessible',
            'status' => 'inaccessible',
            'occurred_at' => now(),
        ]);

        // 3. Unclassified/arbitrary status maps to Unknown (not Recorded)
        $unknownLog = OutputAccessLog::query()->create([
            'output_type' => 'cor_preview',
            'source_record_type' => 'Enrollment',
            'source_record_id' => 103,
            'action' => 'arbitrary_action',
            'status' => 'unrecognized_custom_status',
            'occurred_at' => now(),
        ]);

        // 4. Output access denied or failed maps to Attention with safe summary
        $deniedLog = OutputAccessLog::query()->create([
            'output_type' => 'transcript_preview',
            'source_record_type' => 'TranscriptRequest',
            'source_record_id' => 104,
            'action' => 'denied',
            'status' => 'denied',
            'occurred_at' => now(),
        ]);
        $deniedItem = $projection->find(GovernanceEvidenceProjection::OutputAccess, "output:{$deniedLog->id}");
        $this->assertNotNull($deniedItem);
        $this->assertSame('Attention', $deniedItem['status']);
        $this->assertSame('Output access was denied or failed.', $deniedItem['summary']);

        // 5. Recorded status with raw purpose produces safe classified summary without leaking purpose text
        $recordedLog = OutputAccessLog::query()->create([
            'output_type' => 'report_export',
            'source_record_type' => 'FinanceExport',
            'source_record_id' => 105,
            'actor_role' => 'accounting',
            'action' => 'export',
            'status' => 'generated',
            'purpose' => 'Private internal audit notes that must not leak',
            'occurred_at' => now(),
        ]);
        $recordedItem = $projection->find(GovernanceEvidenceProjection::OutputAccess, "output:{$recordedLog->id}");
        $this->assertNotNull($recordedItem);
        $this->assertSame('Recorded', $recordedItem['status']);
        $this->assertSame('An authorized output or export access was recorded.', $recordedItem['summary']);
        $this->assertStringNotContainsString('Private internal audit notes', $recordedItem['summary']);

        // 6. Attribution: role only -> displays role
        $roleOnlyItem = $projection->find(GovernanceEvidenceProjection::OutputAccess, "output:{$staleLog->id}");
        $this->assertNotNull($roleOnlyItem);
        $this->assertSame('Attention', $roleOnlyItem['status']);
        $this->assertSame('registrar', $roleOnlyItem['actor']);
        $this->assertSame('registrar', $roleOnlyItem['actor_role']);
        $this->assertSame('Output record is stale and requires attention.', $roleOnlyItem['summary']);

        $inaccessibleItem = $projection->find(GovernanceEvidenceProjection::OutputAccess, "output:{$inaccessibleLog->id}");
        $this->assertNotNull($inaccessibleItem);
        $this->assertSame('Attention', $inaccessibleItem['status']);
        $this->assertSame('Output is currently inaccessible.', $inaccessibleItem['summary']);

        // 7. Attribution: missing user and missing role -> displays Unattributed
        $unknownItem = $projection->find(GovernanceEvidenceProjection::OutputAccess, "output:{$unknownLog->id}");
        $this->assertNotNull($unknownItem);
        $this->assertSame('Unknown', $unknownItem['status']);
        $this->assertSame('Unattributed', $unknownItem['actor']);
        $this->assertSame('Unattributed', $unknownItem['actor_role']);
        $this->assertSame('Output access state is unclassified.', $unknownItem['summary']);
    }

    #[Test]
    public function seed_browser_data_database_guard_rejects_non_test_tala_db(): void
    {
        // When exact test_tala_db, no exception is thrown
        BrowserQualificationEnvironment::assertValidDatabase('test_tala_db');

        // When any other database name (e.g. production or test_other), exception is thrown
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Refusing to seed fixtures: database must be exactly 'test_tala_db', got 'tala_production'");
        BrowserQualificationEnvironment::assertValidDatabase('tala_production');
    }

    #[Test]
    public function seed_browser_data_database_guard_rejects_when_config_database_is_not_test_tala_db(): void
    {
        $originalDb = config('database.connections.mysql.database');
        config(['database.connections.mysql.database' => 'tala_production_db']);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("Refusing to seed fixtures: database must be exactly 'test_tala_db', got 'tala_production_db'");
            BrowserQualificationEnvironment::assertValidDatabase();
        } finally {
            config(['database.connections.mysql.database' => $originalDb]);
        }
    }

    #[Test]
    public function clear_replay_cache_database_guard_rejects_when_config_database_is_not_test_tala_db(): void
    {
        $prefix = (string) config('cache.prefix');
        $testKey = 'filament.app_authentication_codes.guard_test_pre_existing_key';
        DB::table('cache')->insert([
            'key' => $prefix.$testKey,
            'value' => serialize('pre_existing_value'),
            'expiration' => time() + 300,
        ]);

        $originalDb = config('database.connections.mysql.database');
        config(['database.connections.mysql.database' => 'tala_production_db']);

        $exceptionThrown = false;
        try {
            BrowserQualificationEnvironment::clearReplayCache();
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            $this->assertStringContainsString("Refusing to seed fixtures: database must be exactly 'test_tala_db', got 'tala_production_db'", $e->getMessage());
        } finally {
            config(['database.connections.mysql.database' => $originalDb]);
        }

        $this->assertTrue($exceptionThrown, 'Expected RuntimeException was not thrown by database guard.');
        // Verify pre-existing cache record was NOT deleted
        $this->assertNotNull(
            DB::table('cache')->where('key', $prefix.$testKey)->first(),
            'Cache cleanup must reject the operation before mutating or deleting anything.'
        );

        // Clean up test key
        DB::table('cache')->where('key', $prefix.$testKey)->delete();
    }

    #[Test]
    public function completion_readiness_and_operational_events_attribute_accurate_roles_and_safe_summaries(): void
    {
        $projection = app(GovernanceEvidenceProjection::class);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');

        // 1. Completion readiness generated by registrar attributes to Registrar, generated_by null attributes to System
        $readiness = CompletionReadinessVersion::factory()->create([
            'generated_by' => $registrar->id,
            'state' => 'COMPLETE',
        ]);

        $readinessSystem = CompletionReadinessVersion::factory()->create([
            'generated_by' => null,
            'state' => 'IN_PROGRESS',
        ]);

        $readinessItem = $projection->find(GovernanceEvidenceProjection::InstitutionalChanges, "readiness:{$readiness->id}");
        $this->assertNotNull($readinessItem);
        $this->assertSame('Registrar', $readinessItem['actor_role']);
        $this->assertSame($registrar->name, $readinessItem['actor']);

        $readinessSystemItem = $projection->find(GovernanceEvidenceProjection::InstitutionalChanges, "readiness:{$readinessSystem->id}");
        $this->assertNotNull($readinessSystemItem);
        $this->assertSame('System', $readinessSystemItem['actor_role']);
        $this->assertSame('System', $readinessSystemItem['actor']);

        // 2. Operational event for applicant submission attributes to Applicant with safe summary
        $applicantEvent = OperationalEvent::query()->create([
            'event_domain' => OperationalEvent::DomainNotifications,
            'event_type' => 'admission_application_submitted',
            'status' => 'PROCESSED',
            'user_id' => $applicant->id,
            'occurred_at' => now(),
        ]);

        $applicantItem = $projection->find(GovernanceEvidenceProjection::SystemEvents, "operational:{$applicantEvent->id}");
        $this->assertNotNull($applicantItem);
        $this->assertSame('Applicant', $applicantItem['actor_role']);
        $this->assertSame($applicant->name, $applicantItem['actor']);
        $this->assertSame('Recorded', $applicantItem['status']);
        $this->assertSame('Admission application intake submitted.', $applicantItem['summary']);

        // 3. Allowed generic subject with null causer (system mutation) attributes to System actor and role
        $systemSubjectLog = activity()
            ->performedOn($registrar) // User is in supportedInstitutionalGenericSubjects
            ->event('created')
            ->log('System created user record');
        assert($systemSubjectLog instanceof Model);

        $systemSubjectItem = $projection->find(GovernanceEvidenceProjection::InstitutionalChanges, "activity:{$systemSubjectLog->getKey()}");
        $this->assertNotNull($systemSubjectItem);
        $this->assertSame('System', $systemSubjectItem['actor']);
        $this->assertSame('System', $systemSubjectItem['actor_role']);
        $this->assertSame('Institutional record created.', $systemSubjectItem['summary']);

        // 4. Activity causer without roles attributes to user name as actor and 'Unattributed' as role
        $rolelessUser = User::factory()->create(['status' => User::StatusActive]);
        $rolelessLog = activity()
            ->causedBy($rolelessUser)
            ->performedOn($rolelessUser)
            ->event('updated')
            ->log('Roleless user updated');
        assert($rolelessLog instanceof Model);

        $rolelessItem = $projection->find(GovernanceEvidenceProjection::InstitutionalChanges, "activity:{$rolelessLog->getKey()}");
        $this->assertNotNull($rolelessItem);
        $this->assertSame($rolelessUser->name, $rolelessItem['actor']);
        $this->assertSame('Unattributed', $rolelessItem['actor_role']);

        // 5. Newly classified specific institutional domain events appear with classified summaries
        $holdLog = activity()
            ->causedBy($registrar)
            ->performedOn($rolelessUser)
            ->event('hold_created')
            ->log('Hold placed');
        assert($holdLog instanceof Model);

        $holdItem = $projection->find(GovernanceEvidenceProjection::InstitutionalChanges, "activity:{$holdLog->getKey()}");
        $this->assertNotNull($holdItem);
        $this->assertSame('Student hold placed.', $holdItem['summary']);
        $this->assertSame('Registrar', $holdItem['actor_role']);
    }

    #[Test]
    public function scoped_cache_cleanup_preserves_unrelated_accounts_and_rate_limiters(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name' => 'System Admin',
                'first_name' => 'System',
                'last_name' => 'Admin',
                'password' => 'password',
                'status' => User::StatusActive,
            ]
        );
        $admin->assignRole(User::StaffRoleSystemSuperAdmin);

        $otherUser = User::firstOrCreate(
            ['email' => 'other_user@example.test'],
            [
                'name' => 'Other User',
                'first_name' => 'Other',
                'last_name' => 'User',
                'password' => 'password',
                'status' => User::StatusActive,
            ]
        );

        $qualificationSecret = 'JBSWY3DPEHPK3PXP';
        $unrelatedSecret = 'OTHERUNRELATEDKEY';

        $g2fa = app(Google2FA::class);
        $ts = $g2fa->getTimestamp();
        $prefix = config('cache.prefix');

        // Populate qualification admin MFA code key in cache
        $qualCode = $g2fa->getCurrentOtp($qualificationSecret);
        $qualMfaKey = 'filament.app_authentication_codes.'.md5($qualificationSecret.$qualCode);
        DB::table('cache')->insert([
            'key' => $prefix.$qualMfaKey,
            'value' => serialize($ts),
            'expiration' => time() + 300,
        ]);

        // Populate unrelated user MFA code key in cache
        $otherCode = '987654';
        $unrelatedMfaKey = 'filament.app_authentication_codes.'.md5($unrelatedSecret.$otherCode);
        DB::table('cache')->insert([
            'key' => $prefix.$unrelatedMfaKey,
            'value' => serialize($ts),
            'expiration' => time() + 300,
        ]);

        // Populate qualification admin mail-self-test rate limiter in cache
        $qualMailKey = "tala:system-health:mail-self-test:{$admin->id}";
        RateLimiter::hit($qualMailKey, 60);

        // Populate unrelated user rate limiter in cache
        $unrelatedMailKey = "tala:system-health:mail-self-test:{$otherUser->id}";
        RateLimiter::hit($unrelatedMailKey, 60);

        $this->assertTrue(RateLimiter::tooManyAttempts($qualMailKey, 1));
        $this->assertTrue(RateLimiter::tooManyAttempts($unrelatedMailKey, 1));

        // Execute the actual BrowserQualificationEnvironment scoped cleanup implementation
        BrowserQualificationEnvironment::clearReplayCache($qualificationSecret, 'admin@example.test');

        // Assert qualification keys were deleted
        $this->assertFalse(RateLimiter::tooManyAttempts($qualMailKey, 1));
        $this->assertNull(DB::table('cache')->where('key', $prefix.$qualMfaKey)->first());

        // Assert unrelated user keys PRESERVED!
        $this->assertTrue(RateLimiter::tooManyAttempts($unrelatedMailKey, 1));
        $this->assertNotNull(DB::table('cache')->where('key', $prefix.$unrelatedMfaKey)->first());
    }

    #[Test]
    public function browser_qualification_environment_safe_health_failure_never_mutates_schema(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertFalse(BrowserQualificationEnvironment::isHealthCaptureFailureEnabled());

        BrowserQualificationEnvironment::enableHealthCaptureFailure(15);
        $this->assertTrue(BrowserQualificationEnvironment::isHealthCaptureFailureEnabled());
        $this->assertTrue(Schema::hasTable('jobs'));

        BrowserQualificationEnvironment::disableHealthCaptureFailure();
        $this->assertFalse(BrowserQualificationEnvironment::isHealthCaptureFailureEnabled());
        $this->assertTrue(Schema::hasTable('jobs'));
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
