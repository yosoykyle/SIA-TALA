<?php

namespace Tests\Feature;

use App\Actions\Reports\ExportOperationalReport;
use App\Actions\Reports\OperationalReportService;
use App\Filament\Pages\ReportsAudit;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\FinancialAccommodation;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\Payment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class TAL75ReportsAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());
        $this->assertNotContains(DB::connection()->getDatabaseName(), ['tala_db', 'tala_test_codex']);

        foreach ([...User::staffRoleNames(), 'student', 'applicant'] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function reports_page_is_explicitly_registered_and_role_scoped(): void
    {
        $this->assertContains(ReportsAudit::class, Filament::getPanel('admin')->getPages());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            $this->actingAs($this->user($role));
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            $this->assertTrue(ReportsAudit::canAccess(), "{$role} should access Reports / Audit.");
            $this->assertTrue(ReportsAudit::shouldRegisterNavigation(), "{$role} should see Reports / Audit navigation.");
        }

        foreach ([User::StaffRoleFaculty, 'student', 'applicant'] as $role) {
            $this->actingAs($this->user($role));
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            $this->assertFalse(ReportsAudit::canAccess(), "{$role} should not access Reports / Audit.");
            $this->assertFalse(ReportsAudit::shouldRegisterNavigation(), "{$role} should not see Reports / Audit navigation.");
        }
    }

    #[Test]
    public function fixed_catalog_is_complete_and_role_scoped(): void
    {
        $reports = app(OperationalReportService::class);

        $this->assertCount(6, $reports->optionsFor($this->user(User::StaffRoleRegistrar)));
        $this->assertCount(5, $reports->optionsFor($this->user(User::StaffRoleAccounting)));
        $this->assertCount(11, $reports->optionsFor($this->user(User::StaffRoleAcademicHead)));
        $this->assertCount(6, $reports->optionsFor($this->user(User::StaffRoleSystemSuperAdmin)));
        $this->assertSame([], $reports->optionsFor($this->user('student')));
        $this->assertSame([], $reports->optionsFor($this->user('applicant')));
    }

    #[Test]
    public function all_authorized_catalog_queries_execute_against_clean_sources(): void
    {
        $reports = app(OperationalReportService::class);

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            $actor = $this->user($role);

            foreach (array_keys($reports->optionsFor($actor)) as $reportKey) {
                $reports->query($reportKey, $actor)->limit(1)->get();
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function authorized_roles_render_the_page_and_student_surfaces_do_not_register_it(): void
    {
        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            $actor = $this->user($role);
            $this->actingAs($actor);
            Filament::setCurrentPanel(Filament::getPanel('admin'));

            Livewire::test(ReportsAudit::class)->assertOk();

            if ($role === User::StaffRoleRegistrar) {
                $this->get(ReportsAudit::getUrl(panel: 'admin'))->assertOk();
            }
        }

        $this->assertNotContains(ReportsAudit::class, Filament::getPanel('student')->getPages());
        $this->assertNotContains(ReportsAudit::class, Filament::getPanel('applicant')->getPages());

        $student = $this->user('student');
        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $response = $this->get(ReportsAudit::getUrl(panel: 'admin'));
        $this->assertContains($response->getStatusCode(), [302, 403]);
    }

    #[Test]
    public function registrar_report_query_and_native_filter_are_term_scoped(): void
    {
        $registrar = $this->user(User::StaffRoleRegistrar);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($program)->create();
        $firstTerm = Term::factory()->create(['label' => 'First Semester']);
        $secondTerm = Term::factory()->create(['label' => 'Second Semester']);
        $included = Enrollment::factory()->for($profile)->for($firstTerm)->create(['status' => 'officially_enrolled']);
        $excluded = Enrollment::factory()->for(StudentProfile::factory()->for($program))->for($secondTerm)->create(['status' => 'capacity_pending']);
        $reports = app(OperationalReportService::class);

        $filtered = $reports->applyFilters(
            OperationalReportService::EnrollmentMaster,
            $reports->query(OperationalReportService::EnrollmentMaster, $registrar),
            ['term_id' => $firstTerm->id],
        )->get();

        $this->assertTrue($filtered->contains($included));
        $this->assertFalse($filtered->contains($excluded));

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ReportsAudit::class)
            ->filterTable('scope', ['term_id' => $firstTerm->id])
            ->assertCanSeeTableRecords([$included])
            ->assertCanNotSeeTableRecords([$excluded]);
    }

    #[Test]
    public function academic_reports_keep_progression_and_unit_load_sources_separate(): void
    {
        $academicHead = $this->user(User::StaffRoleAcademicHead);
        $progression = EnrollmentException::factory()->create([
            'exception_type' => EnrollmentException::TypePrerequisite,
            'state' => EnrollmentException::StateActive,
        ]);
        $unitLoad = EnrollmentException::factory()->create([
            'exception_type' => EnrollmentException::TypeUnitLoad,
            'state' => EnrollmentException::StateActive,
        ]);
        $reports = app(OperationalReportService::class);

        $this->assertSame([$progression->id], $reports->query(OperationalReportService::ProgressionException, $academicHead)->pluck('id')->all());
        $this->assertSame([$unitLoad->id], $reports->query(OperationalReportService::UnitLoadException, $academicHead)->pluck('id')->all());

        $this->expectException(AuthorizationException::class);
        $reports->query(OperationalReportService::StudentLedger, $academicHead);
    }

    #[Test]
    public function curriculum_version_report_is_program_and_status_filterable_and_academic_head_scoped(): void
    {
        $academicHead = $this->user(User::StaffRoleAcademicHead);
        $program = Program::factory()->create();
        $otherProgram = Program::factory()->create();
        $included = CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        CurriculumVersion::factory()->for($program)->create([
            'state' => CurriculumVersion::StateDraft,
        ]);
        CurriculumVersion::factory()->for($otherProgram)->create([
            'state' => CurriculumVersion::StateActive,
        ]);
        $reports = app(OperationalReportService::class);

        $filtered = $reports->applyFilters(
            OperationalReportService::AcademicCurriculumVersion,
            $reports->query(OperationalReportService::AcademicCurriculumVersion, $academicHead),
            ['program_id' => $program->id, 'status' => CurriculumVersion::StateActive],
        )->get();

        $this->assertSame([$included->id], $filtered->pluck('id')->all());

        $accounting = $this->user(User::StaffRoleAccounting);
        $this->expectException(AuthorizationException::class);
        $reports->query(OperationalReportService::AcademicCurriculumVersion, $accounting);
    }

    #[Test]
    public function academic_head_gains_graduation_snapshot_visibility_without_affecting_other_roles(): void
    {
        $registrar = $this->user(User::StaffRoleRegistrar);
        $academicHead = $this->user(User::StaffRoleAcademicHead);
        $accounting = $this->user(User::StaffRoleAccounting);
        $reports = app(OperationalReportService::class);

        $this->assertArrayHasKey(OperationalReportService::GraduationSnapshot, $reports->optionsFor($registrar));
        $this->assertArrayHasKey(OperationalReportService::GraduationSnapshot, $reports->optionsFor($academicHead));
        $this->assertArrayNotHasKey(OperationalReportService::GraduationSnapshot, $reports->optionsFor($accounting));

        $reports->query(OperationalReportService::GraduationSnapshot, $registrar)->limit(1)->get();
        $reports->query(OperationalReportService::GraduationSnapshot, $academicHead)->limit(1)->get();
        $this->addToAssertionCount(2);

        $export = app(ExportOperationalReport::class)->execute(
            $academicHead,
            OperationalReportService::GraduationSnapshot,
            [],
            'Academic Head graduation eligibility review.',
        );
        $this->assertStringContainsString('Student No.', $this->streamedContent($export));

        $this->expectException(AuthorizationException::class);
        $reports->query(OperationalReportService::GraduationSnapshot, $accounting);
    }

    #[Test]
    public function filtered_csv_export_uses_the_exact_query_and_records_complete_audit_evidence(): void
    {
        $accounting = $this->user(User::StaffRoleAccounting);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->for($program)->create([
            'first_name' => '=Formula',
            'last_name' => 'Safe',
        ]);
        $includedTerm = Term::factory()->create(['label' => 'Included Term']);
        $excludedTerm = Term::factory()->create(['label' => 'Excluded Term']);
        $includedPayment = Payment::factory()->for($profile)->for($includedTerm)->create([
            'or_number' => 'OR-INCLUDED',
            'provider_reference' => 'PAY-INCLUDED',
        ]);
        $excludedPayment = Payment::factory()->for($profile)->for($excludedTerm)->create([
            'or_number' => 'OR-EXCLUDED',
            'provider_reference' => 'PAY-EXCLUDED',
        ]);
        $includedLedger = $this->paymentLedger($includedPayment, $accounting, 'Tuition');
        $excludedLedger = $this->paymentLedger($excludedPayment, $accounting, 'Old Balance');
        $reports = app(OperationalReportService::class);
        $filters = ['term_id' => $includedTerm->id];
        $filteredLedgerIds = $reports->applyFilters(
            OperationalReportService::DailyCash,
            $reports->query(OperationalReportService::DailyCash, $accounting),
            $filters,
        )->pluck('id')->all();
        $this->assertSame([$includedLedger->id], $filteredLedgerIds);
        $beforeIncluded = $includedLedger->getRawOriginal('updated_at');
        $beforeExcluded = $excludedLedger->getRawOriginal('updated_at');
        $request = Request::create('/admin/reports-audit', 'GET', server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'TAL-75 test agent',
        ]);

        $response = app(ExportOperationalReport::class)->execute(
            $accounting,
            OperationalReportService::DailyCash,
            $filters,
            'Daily cashier turnover reconciliation.',
            $request,
        );
        $csv = $this->streamedContent($response);

        $this->assertStringContainsString('Transaction Date/Time', $csv);
        $this->assertStringContainsString('OR-INCLUDED', $csv);
        $this->assertStringNotContainsString('OR-EXCLUDED', $csv);
        $this->assertStringContainsString("'=Formula", $csv);
        $this->assertSame($beforeIncluded, $includedLedger->fresh()->getRawOriginal('updated_at'));
        $this->assertSame($beforeExcluded, $excludedLedger->fresh()->getRawOriginal('updated_at'));
        $this->assertSame(2, LedgerEntry::query()->whereKey([$includedLedger->id, $excludedLedger->id])->count());

        $log = OutputAccessLog::query()->sole();
        $this->assertSame('REPORT', $log->output_type);
        $this->assertSame('EXPORT', $log->action);
        $this->assertSame('report:'.OperationalReportService::DailyCash, $log->source_record_type);
        $this->assertSame($accounting->id, $log->actor_user_id);
        $this->assertSame(User::StaffRoleAccounting, $log->actor_role);
        $this->assertSame(1, $log->row_count);
        $this->assertSame('Daily cashier turnover reconciliation.', $log->purpose);
        $this->assertSame(OperationalReportService::SensitivityFinanceData, $log->sensitivity);
        $this->assertSame('generated', $log->status);
        $this->assertSame($includedTerm->id, data_get($log->filter_summary, 'filters.term_id'));
        $this->assertSame('127.0.0.1', data_get($log->request_context, 'ip'));
    }

    #[Test]
    public function sensitive_export_requires_purpose_before_any_log_is_written(): void
    {
        $accounting = $this->user(User::StaffRoleAccounting);
        $reports = app(OperationalReportService::class);

        try {
            app(ExportOperationalReport::class)->execute(
                $accounting,
                OperationalReportService::StudentLedger,
                [],
                null,
            );
            $this->fail('Sensitive export without purpose was not rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purpose', $exception->errors());
            $this->assertSame(0, OutputAccessLog::query()->count());
        }

        $this->actingAs($accounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ReportsAudit::class)
            ->callAction('exportCsv', ['purpose' => ''])
            ->assertHasActionErrors(['purpose' => 'required']);
    }

    #[Test]
    public function fixed_exports_exclude_private_evidence_payloads_tokens_and_diagnostics(): void
    {
        $accounting = $this->user(User::StaffRoleAccounting);
        $profile = StudentProfile::factory()->create();
        $term = Term::factory()->create();
        FinancialAccommodation::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
            'balance_snapshot' => '5000.00',
            'covered_amount' => '2000.00',
            'basis' => 'DSWD_LGU_CERTIFICATION',
            'certification_reference' => 'CERT-PRIVATE-123',
            'private_evidence_reference' => 'private/evidence/secret.pdf',
            'promissory_required' => true,
            'promissory_maker' => 'PRIVATE MAKER',
            'allows_finance_gate' => true,
            'allows_next_term_enrollment' => false,
            'allows_reactivation' => false,
            'allows_record_release' => false,
            'waives_downpayment' => false,
            'authority' => 'Accounting authority',
            'recorded_by' => $accounting->id,
            'status' => FinancialAccommodation::StatusActive,
            'effective_from' => now()->toDateString(),
        ]);
        $reports = app(OperationalReportService::class);
        $response = app(ExportOperationalReport::class)->execute(
            $accounting,
            OperationalReportService::FinancialAccommodation,
            [],
            'Review accommodation commitments.',
        );
        $csv = $this->streamedContent($response);

        $this->assertStringNotContainsString('CERT-PRIVATE-123', $csv);
        $this->assertStringNotContainsString('private/evidence/secret.pdf', $csv);
        $this->assertStringNotContainsString('PRIVATE MAKER', $csv);

        $superAdmin = $this->user(User::StaffRoleSystemSuperAdmin);
        OperationalEvent::query()->create([
            'event_domain' => 'INTEGRATION',
            'integration' => 'PAYMONGO',
            'direction' => 'INBOUND',
            'event_type' => 'payment.paid',
            'external_id' => 'evt-tal75-private',
            'status' => 'PROCESSED',
            'occurred_at' => now(),
            'payload' => ['token' => 'RAW-WEBHOOK-SECRET'],
            'diagnostics' => ['stack' => 'INTERNAL-DIAGNOSTIC'],
        ]);
        $integrationCsv = $this->streamedContent(app(ExportOperationalReport::class)->execute(
            $superAdmin,
            OperationalReportService::IntegrationEvent,
            [],
            'Investigate integration delivery status.',
        ));

        $this->assertStringNotContainsString('RAW-WEBHOOK-SECRET', $integrationCsv);
        $this->assertStringNotContainsString('INTERNAL-DIAGNOSTIC', $integrationCsv);
    }

    #[Test]
    public function paymongo_webhook_report_is_super_admin_only_scoped_filterable_and_allowlisted(): void
    {
        $superAdmin = $this->user(User::StaffRoleSystemSuperAdmin);
        $accounting = $this->user(User::StaffRoleAccounting);
        $included = OperationalEvent::factory()->failed()->create([
            'event_domain' => OperationalEvent::DomainIntegration,
            'integration' => OperationalEvent::IntegrationPayMongo,
            'channel' => OperationalEvent::ChannelWebhook,
            'direction' => OperationalEvent::DirectionInbound,
            'event_type' => 'checkout_session.payment.paid',
            'external_id' => 'evt-c2-included',
            'status' => OperationalEvent::StatusReviewRequired,
            'payload' => ['signature' => 'must-not-render'],
            'diagnostics' => ['reason' => 'must-not-render'],
        ]);
        $excluded = OperationalEvent::factory()->create([
            'event_domain' => OperationalEvent::DomainNotifications,
            'integration' => OperationalEvent::IntegrationMail,
            'channel' => OperationalEvent::ChannelEmail,
            'direction' => OperationalEvent::DirectionOutbound,
        ]);
        $reports = app(OperationalReportService::class);

        $this->assertArrayHasKey(OperationalReportService::PayMongoWebhookEvent, $reports->optionsFor($superAdmin));
        $this->assertArrayNotHasKey(OperationalReportService::PayMongoWebhookEvent, $reports->optionsFor($accounting));

        $filtered = $reports->applyFilters(
            OperationalReportService::PayMongoWebhookEvent,
            $reports->query(OperationalReportService::PayMongoWebhookEvent, $superAdmin),
            ['status' => OperationalEvent::StatusReviewRequired, 'event_type' => 'checkout_session.payment.paid'],
        )->get();

        $this->assertSame([$included->id], $filtered->pluck('id')->all());
        $this->assertFalse($filtered->contains($excluded));
        $this->assertArrayNotHasKey('payload', $filtered->first()->getAttributes());
        $this->assertArrayNotHasKey('diagnostics', $filtered->first()->getAttributes());
        $columnKeys = array_column($reports->columns(OperationalReportService::PayMongoWebhookEvent), 'key');
        $this->assertSame([
            'occurred_at',
            'event_type',
            'external_id',
            'related_record',
            'status',
            'processed_at',
        ], $columnKeys);
        $this->assertNotContains('payload', $columnKeys);
        $this->assertNotContains('diagnostics', $columnKeys);

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ReportsAudit::class)
            ->callAction(TestAction::make('selectReport'), [
                'report_key' => OperationalReportService::PayMongoWebhookEvent,
            ])
            ->assertCanSeeTableRecords([$included])
            ->assertCanNotSeeTableRecords([$excluded])
            ->assertSee('evt-c2-included')
            ->assertDontSee('must-not-render');

        $this->expectException(AuthorizationException::class);
        $reports->query(OperationalReportService::PayMongoWebhookEvent, $accounting);
    }

    #[Test]
    public function report_export_audit_is_visible_only_in_the_super_admin_catalog(): void
    {
        $accounting = $this->user(User::StaffRoleAccounting);
        $reports = app(OperationalReportService::class);
        app(ExportOperationalReport::class)->execute(
            $accounting,
            OperationalReportService::StudentLedger,
            [],
            'Ledger discrepancy review.',
        );
        $log = OutputAccessLog::query()->sole();
        $superAdmin = $this->user(User::StaffRoleSystemSuperAdmin);

        $this->assertTrue($reports->query(OperationalReportService::ReportExport, $superAdmin)->whereKey($log)->exists());
        $this->assertFalse($reports->query(OperationalReportService::GeneratedOutput, $superAdmin)->whereKey($log)->exists());
        $this->assertContains('filter_summary', array_column($reports->columns(OperationalReportService::ReportExport), 'key'));

        $this->expectException(AuthorizationException::class);
        $reports->query(OperationalReportService::ReportExport, $accounting);
    }

    private function paymentLedger(Payment $payment, User $poster, string $category): LedgerEntry
    {
        return LedgerEntry::factory()->for($payment->studentProfile)->create([
            'term_id' => $payment->term_id,
            'direction' => LedgerEntry::DirectionPayment,
            'category' => $category,
            'amount' => $payment->amount,
            'source_type' => Payment::class,
            'source_id' => $payment->id,
            'payment_id' => $payment->id,
            'description' => "Payment {$payment->id}",
            'posted_by' => $poster->id,
            'posted_at' => $payment->paid_at,
            'state' => 'posted',
        ]);
    }

    private function streamedContent(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
