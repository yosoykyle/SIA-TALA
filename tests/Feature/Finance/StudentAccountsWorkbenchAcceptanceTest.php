<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\CreateContextualFinanceExport;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\FinanceExport;
use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TranscriptRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StudentAccountsWorkbenchAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        Role::query()->firstOrCreate([
            'name' => User::StaffRoleAccounting,
            'guard_name' => 'web',
        ]);
    }

    public function test_accounting_has_one_student_accounts_workbench_with_three_separate_tabs(): void
    {
        $accounting = $this->accounting();
        $ordinary = $this->accountFixture('ordinary@example.test');
        $exception = $this->accountFixture('exception@example.test');
        $clearance = $this->accountFixture('clearance@example.test');
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $exception['account']->id,
            'state' => PaymentEvidenceVersion::StateSubmitted,
        ]);
        TranscriptRequest::factory()->create([
            'student_profile_id' => $clearance['enrollment']->student_profile_id,
            'external_request_reference' => 'TOR-SYNTH-WORKBENCH',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($accounting)
            ->test(ListEnrollments::class)
            ->assertSee('Accounts')
            ->assertSee('Payment Exceptions')
            ->assertSee('TOR Clearance');

        $this->assertSame('Student Accounts', $component->instance()->getTitle());

        $component
            ->set('activeTab', 'accounts')
            ->assertCanSeeTableRecords([$ordinary['enrollment'], $exception['enrollment'], $clearance['enrollment']])
            ->set('activeTab', 'payment_exceptions')
            ->assertCanSeeTableRecords([$exception['enrollment']])
            ->assertCanNotSeeTableRecords([$ordinary['enrollment'], $clearance['enrollment']])
            ->set('activeTab', 'tor_clearance')
            ->assertCanSeeTableRecords([$clearance['enrollment']])
            ->assertCanNotSeeTableRecords([$ordinary['enrollment'], $exception['enrollment']]);

        $this->assertNotContains(
            'App\\Filament\\Resources\\Payments\\PaymentResource',
            Filament::getPanel('admin')->getResources(),
        );
        $this->assertFalse(Route::has('filament.admin.resources.payments.index'));
        $this->assertFalse(Route::has('filament.admin.resources.payments.view'));
    }

    public function test_account_status_export_uses_the_fixed_private_contract_and_records_no_rows_without_a_file(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('formula@example.test', '=FORMULA');
        $asOf = CarbonImmutable::parse('2026-08-25 18:00:00', 'Asia/Manila');

        $export = app(CreateContextualFinanceExport::class)->createAccountStatus(
            $accounting,
            'Reconcile the visible Student Accounts queue.',
            new Collection([$fixture['enrollment']]),
            [
                'active_tab' => 'accounts',
                'filters' => ['term' => ['value' => $fixture['term']->id]],
                'search' => '=FORMULA',
                'sort' => ['column' => 'updated_at', 'direction' => 'desc'],
            ],
            $asOf,
        );

        $this->assertSame(FinanceExport::TypeAccountStatus, $export->type);
        $this->assertSame(FinanceExport::OutcomeGenerated, $export->outcome);
        $this->assertSame('tala-account-status-20260825-180000-PHT.csv', $export->downloadFilename());
        Storage::disk('local')->assertExists($export->path);

        $contents = Storage::disk('local')->get($export->path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $this->assertStringContainsString("\r\n", $contents);
        $lines = explode("\r\n", substr($contents, 3));
        $this->assertSame([
            'Account Reference',
            'Person Reference',
            'Program',
            'Term',
            'Assessment Total (PHP)',
            'Required Now (PHP)',
            'Verified Payment Applied (PHP)',
            'Approved Coverage Applied (PHP)',
            'Current Due (PHP)',
            'Projection State',
            'Satisfaction Basis',
            'Assessment Basis',
            'Source Version or Authority Reference',
            'As of (Asia/Manila)',
        ], str_getcsv($lines[0], ',', '"', '\\'));
        $this->assertStringContainsString("'=FORMULA", $contents);

        $this->actingAs($accounting)
            ->get(route('finance.exports.download', $export))
            ->assertOk()
            ->assertDownload($export->downloadFilename());
        $this->actingAs($fixture['learner'])
            ->get(route('finance.exports.download', $export))
            ->assertForbidden();

        $noRows = app(CreateContextualFinanceExport::class)->createAccountStatus(
            $accounting,
            'Confirm an empty filtered queue.',
            new Collection,
            ['active_tab' => 'accounts', 'filters' => ['state' => ['value' => 'missing']]],
            $asOf,
        );

        $this->assertSame(FinanceExport::OutcomeNoRows, $noRows->outcome);
        $this->assertSame(0, $noRows->row_count);
        $this->assertNull($noRows->path);
        $this->assertNull($noRows->checksum);
    }

    public function test_verified_payments_export_is_fixed_to_one_selected_account_and_current_filters(): void
    {
        Storage::fake('local');
        $accounting = $this->accounting();
        $fixture = $this->accountFixture('learner@example.test', 'STU-EXPORT-001');
        $other = $this->accountFixture('other@example.test', 'STU-EXPORT-002');
        $postedAt = CarbonImmutable::parse('2026-08-25 18:30:00', 'Asia/Manila');
        $payment = Payment::factory()->create([
            'term_account_id' => $fixture['account']->id,
            'term_id' => $fixture['term']->id,
            'provider_reference' => 'PAYMENT-SYNTH-001',
            'external_check_reference' => 'BANK-EXTERNAL-12345678',
            'amount' => '3000.00',
            'channel' => 'bank_transfer',
            'state' => Payment::StatePosted,
            'evidence_status' => 'verified',
            'verification_basis' => 'IndependentSourceCheck',
            'verified_at' => $postedAt->utc(),
        ]);
        Payment::factory()->create([
            'term_account_id' => $other['account']->id,
            'term_id' => $other['term']->id,
            'provider_reference' => 'PAYMENT-OTHER-001',
            'amount' => '9000.00',
            'state' => Payment::StatePosted,
            'verified_at' => $postedAt->utc(),
        ]);

        $export = app(CreateContextualFinanceExport::class)->createVerifiedPayments(
            $accounting,
            $fixture['account'],
            'Verify postings for the selected account.',
            ['state' => Payment::StatePosted, 'from' => '2026-08-25', 'until' => '2026-08-25'],
            $postedAt,
        );

        $this->assertSame(FinanceExport::TypeVerifiedPayments, $export->type);
        $this->assertSame('tala-verified-payments-20260825-183000-PHT.csv', $export->downloadFilename());
        $contents = Storage::disk('local')->get($export->path);
        $lines = explode("\r\n", substr($contents, 3));
        $this->assertSame([
            'Payment Reference',
            'Account Reference',
            'Person Reference',
            'Term',
            'Amount (PHP)',
            'Channel',
            'Masked External Reference',
            'Posted At (Asia/Manila)',
            'Verification Basis',
            'Current State',
        ], str_getcsv($lines[0], ',', '"', '\\'));
        $this->assertStringContainsString($payment->provider_reference, $contents);
        $this->assertStringContainsString('••••5678', $contents);
        $this->assertStringNotContainsString('PAYMENT-OTHER-001', $contents);
        $this->assertStringContainsString('3000.00', $contents);
        $this->assertStringContainsString('2026-08-25T18:30:00+08:00', $contents);
    }

    /** @return array{learner:User,enrollment:Enrollment,account:TermAccount,assessment:Assessment,term:Term} */
    private function accountFixture(string $email, ?string $studentNumber = null): array
    {
        $learner = User::factory()->create([
            'email' => $email,
            'status' => User::StatusActive,
        ]);
        $program = Program::factory()->create();
        $profile = StudentProfile::factory()->create(array_filter([
            'user_id' => $learner->id,
            'program_id' => $program->id,
            'student_number' => $studentNumber,
        ], fn (mixed $value): bool => $value !== null));
        $term = Term::factory()->create();
        $enrollment = Enrollment::factory()->create([
            'credential_user_id' => $learner->id,
            'student_profile_id' => $profile->id,
            'term_id' => $term->id,
        ]);
        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $learner->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'assessment_basis' => 'PublishedFeePlan',
            'authority_reference' => 'SYNTH-FEE-AUTHORITY',
            'total' => '12000.00',
            'subtotal' => '12000.00',
            'state' => Assessment::StateActive,
        ]);
        AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'code' => 'ENROLLMENT',
            'label' => 'Enrollment obligation',
            'purpose' => 'Enrollment',
            'amount' => '12000.00',
            'due_at' => '2026-08-25 17:00:00',
            'required_for_enrollment' => true,
        ]);

        return compact('learner', 'enrollment', 'account', 'assessment', 'term');
    }

    private function accounting(): User
    {
        $accounting = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $accounting->assignRole(User::StaffRoleAccounting);

        return $accounting;
    }
}
