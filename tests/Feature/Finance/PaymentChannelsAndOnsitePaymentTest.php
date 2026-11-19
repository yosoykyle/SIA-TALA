<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Finance\CashierSchedule;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\PaymentConfirmationService;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\SchoolPaymentChannels;
use App\Actions\Finance\StudentTermAccountPresenter;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Filament\Applicant\Pages\Enrollment as ApplicantEnrollment;
use App\Filament\Pages\PaymentChannels;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Student\Pages\Enrollment as StudentEnrollment;
use App\Filament\Student\Pages\Finance as StudentFinance;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\Enrollment;
use App\Models\FinanceExport;
use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use App\Models\RegistrationProposalConfirmation;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\SystemSetting;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TermCalendarPackage;
use App\Models\TermDatedException;
use App\Models\User;
use App\Support\SafeUserNotification;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class PaymentChannelsAndOnsitePaymentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        Role::query()->firstOrCreate([
            'name' => User::StaffRoleAccounting,
            'guard_name' => 'web',
        ]);
    }

    public function test_accounting_can_load_and_save_payment_channels_with_role_and_validation_guards(): void
    {
        $accounting = $this->accountingUser();
        $registrar = $this->userWithRole(User::StaffRoleRegistrar);
        $student = $this->userWithRole('student');
        $applicant = $this->userWithRole('applicant');

        $this->assertTrue(Route::has('filament.admin.pages.payment-channels'));
        $this->assertFalse(Route::has('filament.admin.resources.system-settings.index'));
        $this->assertFalse(class_exists('App\\Filament\\Resources\\SystemSettingResource'));
        $this->assertFalse(class_exists('App\\Filament\\Resources\\SystemSettings\\SystemSettingResource'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach ([$registrar, $student, $applicant] as $unauthorized) {
            Livewire::actingAs($unauthorized)
                ->test(PaymentChannels::class)
                ->assertForbidden();

            $this->expectException(AuthorizationException::class);
            app(SchoolPaymentChannels::class)->update([], $unauthorized);
        }
    }

    public function test_non_accounting_users_cannot_save_payment_channels_via_action_service(): void
    {
        $registrar = $this->userWithRole(User::StaffRoleRegistrar);

        $this->expectException(AuthorizationException::class);
        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => ['enabled' => false],
            'gcash' => ['enabled' => false],
            'cash' => ['enabled' => true, 'location' => 'Cashier Window 1'],
        ], $registrar);
    }

    public function test_payment_destinations_start_empty_and_enabling_a_channel_creates_only_blank_fields(): void
    {
        SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->delete();
        $channels = app(SchoolPaymentChannels::class)->current();
        $this->assertSame([], $channels['bank_transfer']['accounts']);
        $this->assertSame([], $channels['e_wallets']['accounts']);
        $this->assertFalse(app(SchoolPaymentChannels::class)->hasEnabledDigitalChannels());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($this->accountingUser())->test(PaymentChannels::class);

        foreach (['bank_transfer' => 'bank_name', 'e_wallets' => 'provider'] as $group => $providerField) {
            $page->set("data.{$group}.enabled", true);
            $accounts = array_values($page->get("data.{$group}.accounts"));
            $this->assertCount(1, $accounts);
            foreach ([$providerField, 'account_name', 'account_number'] as $field) {
                $this->assertEmpty($accounts[0][$field]);
            }
        }

        $page->call('save')->assertHasErrors();
        $this->assertSame(0, SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->count());
    }

    public function test_empty_digital_previews_explain_missing_details_without_showing_fake_payment_destinations(): void
    {
        $channels = [
            'bank_transfer' => ['enabled' => true, 'accounts' => [['id' => 'new-bank']]],
            'e_wallets' => ['enabled' => true, 'accounts' => [['id' => 'new-wallet']]],
            'cash' => ['enabled' => false],
        ];
        $render = fn (array $state): string => Blade::render(
            '@include("filament.student.pages.how-to-pay", ["display" => $display, "variant" => "preview"])',
            ['display' => app(SchoolPaymentChannels::class)->forDisplay($state)],
        );

        $empty = $render($channels);
        $this->assertStringContainsString('Add bank account details above to preview payment instructions.', $empty);
        $this->assertStringContainsString('Add e-wallet account details above to preview payment instructions.', $empty);
        $this->assertStringContainsString('Details incomplete', $empty);
        $this->assertStringNotContainsString('Not set yet', $empty);
        $this->assertStringNotContainsString('data-copy-reference=', $empty);

        $channels['bank_transfer']['accounts'][0] += [
            'bank_name' => 'School bank',
            'account_name' => 'School account',
            'account_number' => '0012-3456-78',
        ];
        $configured = $render($channels);
        $this->assertStringContainsString('data-copy-reference="0012-3456-78"', $configured);
        $this->assertStringContainsString('School account', $configured);
        $this->assertStringNotContainsString('Add bank account details above', $configured);
        $this->assertStringContainsString('Add e-wallet account details above', $configured);
    }

    public function test_invalid_payment_setting_fields_are_rejected_without_creating_a_version(): void
    {
        $accounting = $this->accountingUser();
        $service = app(SchoolPaymentChannels::class);
        $before = SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->count();
        $cases = [
            ['bank_transfer', 'malformed'],
            ['bank_transfer.enabled', 'yes'],
            ['bank_transfer.accounts', 'malformed'],
            ['bank_transfer.accounts.0', 'malformed'],
            ['bank_transfer.accounts.0.bank_name', ['unexpected']],
            ['bank_transfer.accounts.0.branch', str_repeat('x', 121)],
            ['bank_transfer.accounts.0.account_name', str_repeat('x', 121)],
            ['bank_transfer.accounts.0.account_number', ['unexpected']],
            ['bank_transfer.accounts.0.instructions', str_repeat('x', 501)],
            ['e_wallets.accounts.0.provider', ['unexpected']],
            ['cash.location', ['unexpected']],
            ['cash.instructions', str_repeat('x', 501)],
            ['cash.schedule.weekly.1', 'malformed'],
            ['cash.schedule.weekly.1.open', 'yes'],
            ['cash.schedule.weekly.1.opens_at', ['unexpected']],
            ['cash.schedule.timezone', 'Unknown/Zone'],
            ['cash.schedule.cutoff_note', str_repeat('x', 256)],
            ['cash.schedule.exceptions.0', 'malformed'],
            ['cash.schedule.exceptions.0.label', str_repeat('x', 161)],
            ['reason', ['unexpected']],
        ];

        foreach ($cases as [$field, $value]) {
            $payload = ['cash' => ['enabled' => true, 'location' => 'Cashier']];
            data_set($payload, $field, $value);
            try {
                $service->update($payload, $accounting);
                $this->fail("Invalid {$field} was accepted.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
            $this->assertSame($before, SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->count());
        }
    }

    public function test_bank_account_numbers_preserve_leading_zeroes_and_duplicate_account_ids_cannot_be_saved(): void
    {
        $actor = $this->accountingUser();
        $service = app(SchoolPaymentChannels::class);
        $payload = [
            'bank_transfer' => ['enabled' => true, 'accounts' => [[
                'id' => 'school-deposit-account',
                'bank_name' => 'School bank',
                'account_name' => 'School deposit account',
                'account_number' => '0012 3456 7890 1234',
            ]]],
        ];
        $saved = $service->update($payload, $actor);
        $this->assertSame('0012 3456 7890 1234', $saved['bank_transfer']['accounts'][0]['account_number']);
        $before = SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->count();

        $payload['bank_transfer']['accounts'][] = $payload['bank_transfer']['accounts'][0];
        try {
            $service->update($payload, $actor);
            $this->fail('Duplicate account identities were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bank_transfer.accounts.1.id', $exception->errors());
        }
        $this->assertSame($before, SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->count());
    }

    public function test_accounting_saves_payment_channels_into_system_setting_and_rejects_incomplete_enabled_channels(): void
    {
        $accounting = $this->accountingUser();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($accounting)
            ->test(PaymentChannels::class)
            ->assertSee('View only')
            ->assertActionVisible('editChannels')
            ->assertActionHidden('saveChannels')
            ->call('startEditing')
            ->assertActionVisible('cancelEditing')
            ->assertActionHidden('saveChannels')
            ->assertDontSee('Save payment channels')
            ->assertSet('data.cash.enabled', true);

        $beforeCount = SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->count();
        $page->set('data.bank_transfer.enabled', true)
            ->assertActionVisible('saveChannels')
            ->assertSee('Save payment channels')
            ->set('data.e_wallets.enabled', true);
        $bankKey = (string) array_key_first($page->get('data.bank_transfer.accounts'));
        $walletKey = (string) array_key_first($page->get('data.e_wallets.accounts'));

        $page->set('data.bank_transfer.enabled', true)
            ->set("data.bank_transfer.accounts.{$bankKey}.bank_name", '')
            ->set("data.bank_transfer.accounts.{$bankKey}.account_name", '')
            ->set("data.bank_transfer.accounts.{$bankKey}.account_number", '')
            ->call('save')
            ->assertHasErrors([
                "data.bank_transfer.accounts.{$bankKey}.bank_name",
                "data.bank_transfer.accounts.{$bankKey}.account_name",
                "data.bank_transfer.accounts.{$bankKey}.account_number",
            ]);

        $this->assertSame($beforeCount, SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->count());

        $page->set('data.bank_transfer.enabled', true)
            ->set("data.bank_transfer.accounts.{$bankKey}.bank_name", 'Land Bank of the Philippines')
            ->set("data.bank_transfer.accounts.{$bankKey}.branch", 'Main Campus')
            ->set("data.bank_transfer.accounts.{$bankKey}.account_name", 'TALA State College')
            ->set("data.bank_transfer.accounts.{$bankKey}.account_number", '0011-2233-44')
            ->set("data.bank_transfer.accounts.{$bankKey}.instructions", 'Include enrollment reference.')
            ->set('data.e_wallets.enabled', true)
            ->set("data.e_wallets.accounts.{$walletKey}.provider", 'GCash')
            ->set("data.e_wallets.accounts.{$walletKey}.account_name", 'TALA Official Cashier')
            ->set("data.e_wallets.accounts.{$walletKey}.account_number", '09171234567')
            ->set("data.e_wallets.accounts.{$walletKey}.instructions", 'Upload screenshot right after payment.')
            ->set('data.cash.enabled', true)
            ->set('data.cash.location', 'Finance Window 1, Ground Floor')
            ->set('data.cash.hours', 'Mon-Fri 8:00 AM - 4:30 PM')
            ->set('data.reason', 'Configured semester payment channels')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Payment channels saved');

        $saved = SystemSetting::query()
            ->where('key', SchoolPaymentChannels::SettingKey)
            ->latest('version')
            ->first();

        $this->assertInstanceOf(SystemSetting::class, $saved);
        $this->assertSame('Active', $saved->status);

        $enabledKeys = collect(app(SchoolPaymentChannels::class)->enabledChannels())->pluck('key')->all();
        $this->assertSame(['bank_transfer', 'e_wallets', 'cash'], $enabledKeys);
    }

    public function test_payment_channels_live_preview_reflects_unsaved_toggles_and_cash_fallback_truthfully(): void
    {
        $accounting = $this->accountingUser();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($accounting)
            ->test(PaymentChannels::class)
            ->assertSee('Payment methods shown')
            ->assertSee('Students and applicants can pay by cash at the campus cashier.')
            ->assertSeeHtml('accounting-overview-strip--active')
            ->assertDontSee('Digital accounts')
            ->assertDontSeeHtml('payment-channels-summary__pills')
            ->assertDontSee('Configuration')
            ->assertDontSee('School defaults')
            ->assertDontSee('Revision')
            ->assertDontSee('Default configuration')
            ->assertDontSee('Onsite Cashier live')
            ->assertDontSee('Onsite only: electronic transfers hidden')
            ->assertSee('How to pay')
            ->assertSeeHtml('&ldquo;How to pay&rdquo; preview')
            ->assertDontSee('Unsaved');

        $page->call('startEditing')
            ->set('data.e_wallets.enabled', true);
        $walletKey = (string) array_key_first($page->get('data.e_wallets.accounts'));

        $page
            ->set("data.e_wallets.accounts.{$walletKey}.provider", 'GCash')
            ->set("data.e_wallets.accounts.{$walletKey}.account_number", '0917 555 0199')
            ->assertSee('Changes not saved')
            ->assertSee('The preview shows your changes. Students and applicants see the saved instructions until you save.')
            ->assertDontSeeHtml('payment-channels-summary__pills')
            ->assertSee('Unsaved')
            ->assertSee('0917 555 0199');

        $page->set('data.e_wallets.enabled', false)
            ->set('data.bank_transfer.enabled', false)
            ->set('data.cash.enabled', false)
            ->assertSee('Changes not saved')
            ->assertSee('Saving will show standard campus cashier instructions.')
            ->assertDontSee('Onsite only: electronic transfers hidden')
            ->assertSee('Fallback');

        $page->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Payment channels saved')
            ->assertSee('Cashier instructions shown')
            ->assertSee('All methods are off. Students and applicants see the standard campus cashier instructions.')
            ->assertSee('Last saved')
            ->assertDontSee('School defaults')
            ->assertDontSee('Onsite only: electronic transfers hidden')
            ->assertDontSee('Unsaved');

        $page->set('data.bank_transfer.enabled', true)
            ->set('data.e_wallets.enabled', true);
        $bankKey = (string) array_key_first($page->get('data.bank_transfer.accounts'));
        $walletKey = (string) array_key_first($page->get('data.e_wallets.accounts'));

        $page->set('data.bank_transfer.enabled', true)
            ->set("data.bank_transfer.accounts.{$bankKey}.bank_name", 'Land Bank of the Philippines')
            ->set("data.bank_transfer.accounts.{$bankKey}.account_name", 'TALA State College')
            ->set("data.bank_transfer.accounts.{$bankKey}.account_number", '0011-2233-44')
            ->set('data.e_wallets.enabled', true)
            ->set("data.e_wallets.accounts.{$walletKey}.provider", 'GCash')
            ->set("data.e_wallets.accounts.{$walletKey}.account_name", 'TALA Official Cashier')
            ->set("data.e_wallets.accounts.{$walletKey}.account_number", '0917 555 0199')
            ->set('data.cash.enabled', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Payment channels saved')
            ->assertSee('Payment instructions saved')
            ->assertSee('Students and applicants can pay by bank transfer, e-wallets or cash at the campus cashier.')
            ->assertDontSeeHtml('payment-channels-summary__pills')
            ->assertSee('Last saved');
    }

    public function test_enabled_payment_channels_propagate_to_presenter_student_finance_and_enrollment_where_to_pay(): void
    {
        $accounting = $this->accountingUser();

        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [[
                    'id' => 'bank-landbank-1',
                    'bank_name' => 'Land Bank of the Philippines',
                    'account_name' => 'TALA State College',
                    'account_number' => '0011-2233-44',
                    'instructions' => 'Use your student number as reference.',
                ]],
            ],
            'e_wallets' => [
                'enabled' => false,
                'accounts' => [[
                    'id' => 'ewallet-gcash-hidden',
                    'provider' => 'GCash',
                    'account_name' => 'Hidden GCash Account',
                    'account_number' => '09998887777',
                ]],
            ],
            'cash' => [
                'enabled' => true,
                'location' => 'Finance Window 1',
                'hours' => 'Mon-Fri 8:00 AM - 4:30 PM',
            ],
        ], $accounting, 'Enable Bank Transfer and Campus Cashier only');

        $studentFixture = $this->unpaidAccountFixture(isStudent: true);
        $presented = app(StudentTermAccountPresenter::class)->forUser($studentFixture['learner']);
        $this->assertSame(['bank_transfer', 'cash'], collect($presented['payment_channels'])->pluck('key')->all());

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($studentFixture['learner'])
            ->test(StudentFinance::class)
            ->assertSee('How to pay')
            ->assertSee('Land Bank of the Philippines')
            ->assertSee('0011-2233-44')
            ->assertSee('Finance Window 1')
            ->assertDontSee('09998887777');

        Livewire::actingAs($studentFixture['learner'])
            ->test(StudentEnrollment::class)
            ->assertSee('How to pay')
            ->assertSee('Land Bank of the Philippines')
            ->assertSee('0011-2233-44')
            ->assertDontSee('09998887777');

        $applicantFixture = $this->unpaidAccountFixture(isStudent: false);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($applicantFixture['learner'])
            ->test(ApplicantEnrollment::class)
            ->assertSee('How to pay')
            ->assertSee('Land Bank of the Philippines')
            ->assertSee('Finance Window 1')
            ->assertDontSee('09998887777');

        app(PaymentConfirmationService::class)->confirmManualPayment(
            enrollmentId: (int) $studentFixture['enrollment']->id,
            amount: '48000.00',
            channel: 'cash',
            paymentReference: 'CASH-FULL-8801',
            actor: $accounting,
            orNumber: 'OR-FULL-8801',
        );

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($studentFixture['learner'])
            ->test(StudentFinance::class)
            ->assertDontSee('How to pay');
    }

    public function test_accounting_records_onsite_payment_for_pre_student_applicant_and_clears_finance_readiness_without_proof_upload(): void
    {
        $accounting = $this->accountingUser();
        $registrar = $this->userWithRole(User::StaffRoleRegistrar);
        $fixture = $this->unpaidAccountFixture(isStudent: false);
        $enrollment = $fixture['enrollment'];

        $this->assertNull($enrollment->student_profile_id);
        $this->assertNotSame('Cleared', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($enrollment)['state']);
        $this->assertFalse(app(RegistrationReadinessQuery::class)->for($enrollment)['finance']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $enrollment->id])
            ->assertActionHidden('recordOnsitePayment');

        Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $enrollment->id])
            ->mountAction('recordOnsitePayment')
            ->assertActionMounted('recordOnsitePayment')
            ->setActionData([
                'channel' => 'cash',
                'amount' => '12000.00',
                'or_number' => 'OR-ONSITE-8801',
                'payment_reference' => 'CASHIER-WALKIN-8801',
                'confirmed_at' => now(config('app.display_timezone'))->subMinute()->toDateTimeString(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Onsite payment recorded and applied to this account.');

        $payment = Payment::query()->where('term_account_id', $fixture['account']->id)->sole();
        $this->assertNull($payment->student_profile_id);
        $this->assertSame(Payment::StatePosted, $payment->state);
        $this->assertSame('verified', $payment->evidence_status);
        $this->assertSame('cash', $payment->channel);
        $this->assertSame('OR-ONSITE-8801', $payment->or_number);
        $this->assertSame('12000.00', $payment->amount);
        $this->assertSame('12000.00', $payment->allocations->sole()->amount);
        $this->assertSame(0, $fixture['account']->paymentEvidenceVersions()->count());

        $freshEnrollment = $enrollment->fresh();
        $this->assertSame('Cleared', app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($freshEnrollment)['state']);
        $readiness = app(RegistrationReadinessQuery::class)->for($freshEnrollment);
        $this->assertTrue($readiness['finance']);
        $this->assertNotContains('Accounting clearance', $readiness['blockers']);
    }

    public function test_inline_map_official_receipt_maps_verified_proof_payment_and_rejects_duplicate_or(): void
    {
        Storage::fake('local');

        $accounting = $this->accountingUser();
        $this->enableDefaultDigitalChannels($accounting);
        $fixture = $this->unpaidAccountFixture(isStudent: false);
        $enrollment = $fixture['enrollment'];

        $firstEvidence = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'],
            $fixture['learner'],
            UploadedFile::fake()->image('walkin-receipt-1.png'),
            '12000.00',
            'bank_transfer',
            CarbonImmutable::now(config('app.timezone'))->subHour(),
            'BANK-PROOF-8802',
        );
        $firstPayment = app(ReviewPaymentEvidence::class)->verify(
            $firstEvidence,
            $accounting,
            '12000.00',
            'BANK-CHECK-8802',
        );
        $this->assertNull($firstPayment->or_number);

        $secondFixture = $this->unpaidAccountFixture(isStudent: false);
        $secondEvidence = app(SubmitPaymentEvidence::class)->execute(
            $secondFixture['account'],
            $secondFixture['learner'],
            UploadedFile::fake()->image('walkin-receipt-2.png', 24, 24),
            '12000.00',
            'gcash_manual',
            CarbonImmutable::now(config('app.timezone'))->subMinutes(30),
            'GCASH-PROOF-8803',
        );
        $secondPayment = app(ReviewPaymentEvidence::class)->verify(
            $secondEvidence,
            $accounting,
            '12000.00',
            'GCASH-CHECK-8803',
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $enrollment->id]);

        $page->mountAction('mapOfficialReceipt', ['payment' => $firstPayment->id])
            ->assertActionMounted('mapOfficialReceipt')
            ->setActionData(['payment_id' => $firstPayment->id, 'or_number' => 'OR-MAP-8802'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Official Receipt mapped');

        $this->assertSame('OR-MAP-8802', $firstPayment->fresh()->or_number);
        $this->assertSame($accounting->id, $firstPayment->fresh()->or_mapped_by);

        $secondPage = Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $secondFixture['enrollment']->id]);

        $secondPage->mountAction('mapOfficialReceipt', ['payment' => $secondPayment->id])
            ->setActionData(['payment_id' => $secondPayment->id, 'or_number' => 'OR-MAP-8802'])
            ->callMountedAction()
            ->assertNotified('Official Receipt was not mapped');

        $this->assertNull($secondPayment->fresh()->or_number);
    }

    public function test_cashier_schedule_rejects_invalid_hours_missing_exception_label_and_reversed_dates(): void
    {
        $schedule = CashierSchedule::defaultSchedule();
        $schedule['weekly'][1] = ['open' => true, 'opens_at' => '08:00', 'closes_at' => '08:00'];
        $schedule['weekly'][2] = ['open' => true, 'opens_at' => '17:00', 'closes_at' => '09:00'];
        $schedule['exceptions'] = [
            [
                'starts_on' => '2026-11-10',
                'ends_on' => '2026-11-09',
                'status' => CashierSchedule::StatusClosed,
                'label' => '   ',
            ],
            [
                'starts_on' => '2026-11-14',
                'ends_on' => '2026-11-14',
                'status' => CashierSchedule::StatusCustomHours,
                'opens_at' => '14:00',
                'closes_at' => '12:00',
                'label' => 'Saturday special window',
            ],
        ];

        try {
            CashierSchedule::validate($schedule);
            $this->fail('Expected CashierSchedule::validate() to reject invalid schedule bounds.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();

            $this->assertSame(
                ['Monday closing time must be after opening time.'],
                $errors['cash.schedule.weekly.1.closes_at'] ?? null,
            );
            $this->assertSame(
                ['Tuesday closing time must be after opening time.'],
                $errors['cash.schedule.weekly.2.closes_at'] ?? null,
            );
            $this->assertSame(
                ['End date must be on or after the start date.'],
                $errors['cash.schedule.exceptions.0.ends_on'] ?? null,
            );
            $this->assertSame(
                ['Exception label is required.'],
                $errors['cash.schedule.exceptions.0.label'] ?? null,
            );
            $this->assertSame(
                ['Closing time must be after opening time.'],
                $errors['cash.schedule.exceptions.1.closes_at'] ?? null,
            );
        }
    }

    public function test_cashier_schedule_derives_summary_live_today_status_and_month_calendars(): void
    {
        $schedule = CashierSchedule::defaultSchedule();
        $schedule['weekly'][6] = ['open' => true, 'opens_at' => '08:00', 'closes_at' => '12:00'];
        $schedule['exceptions'] = [
            [
                'starts_on' => '2026-11-02',
                'ends_on' => null,
                'status' => CashierSchedule::StatusClosed,
                'label' => "All Souls' Day",
            ],
            [
                'starts_on' => '2026-11-07',
                'ends_on' => '2026-11-07',
                'status' => CashierSchedule::StatusCustomHours,
                'opens_at' => '09:00',
                'closes_at' => '15:00',
                'label' => 'Enrollment Saturday',
            ],
        ];

        $normalized = CashierSchedule::normalize($schedule);

        $this->assertSame(
            "Mon\u{2013}Fri, 8:00 AM \u{2013} 5:00 PM \u{00B7} Sat, 8:00 AM \u{2013} 12:00 PM",
            CashierSchedule::formatHoursSummary($normalized),
        );

        $openStatus = CashierSchedule::todayStatus(
            $normalized,
            CarbonImmutable::parse('2026-11-03 10:30:00', 'Asia/Manila'),
        );
        $this->assertSame('open_now', $openStatus['status']);
        $this->assertSame('Open now', $openStatus['badge']);
        $this->assertSame('success', $openStatus['tone']);
        $this->assertSame('Open today until 5:00 PM', $openStatus['detail']);
        $this->assertSame("8:00 AM \u{2013} 5:00 PM", $openStatus['hours_label']);

        $holidayStatus = CashierSchedule::todayStatus(
            $normalized,
            CarbonImmutable::parse('2026-11-02 10:30:00', 'Asia/Manila'),
        );
        $this->assertSame('closed_today', $holidayStatus['status']);
        $this->assertSame('Closed today', $holidayStatus['badge']);
        $this->assertSame('gray', $holidayStatus['tone']);
        $this->assertSame("All Souls' Day", $holidayStatus['exception_label']);
        $this->assertSame("All Souls' Day \u{00B7} Opens tomorrow at 8:00 AM", $holidayStatus['detail']);

        $customStatus = CashierSchedule::todayStatus(
            $normalized,
            CarbonImmutable::parse('2026-11-07 10:30:00', 'Asia/Manila'),
        );
        $this->assertSame('special_hours', $customStatus['status']);
        $this->assertSame("Special hours \u{00B7} Open now", $customStatus['badge']);
        $this->assertSame('warning', $customStatus['tone']);
        $this->assertSame('Enrollment Saturday', $customStatus['exception_label']);
        $this->assertSame("Enrollment Saturday \u{00B7} Open until 3:00 PM", $customStatus['detail']);
        $this->assertSame("9:00 AM \u{2013} 3:00 PM", $customStatus['hours_label']);

        $calendars = CashierSchedule::monthCalendars(
            $normalized,
            CarbonImmutable::parse('2026-11-01', 'Asia/Manila'),
            monthCount: 2,
            today: CarbonImmutable::parse('2026-11-03', 'Asia/Manila'),
        );

        $this->assertCount(2, $calendars);
        $this->assertSame('2026-11', $calendars[0]['month_key']);
        $this->assertSame('November 2026', $calendars[0]['month_label']);
        $this->assertSame('2026-12', $calendars[1]['month_key']);

        $novemberCells = collect($calendars[0]['weeks'])
            ->flatten(1)
            ->filter(fn (array $cell): bool => $cell['in_month'])
            ->keyBy('date');

        $this->assertSame('holiday', $novemberCells['2026-11-02']['state']);
        $this->assertSame("All Souls' Day", $novemberCells['2026-11-02']['label']);
        $this->assertSame('Closed', $novemberCells['2026-11-02']['hours_label']);

        $this->assertSame('open', $novemberCells['2026-11-03']['state']);
        $this->assertTrue($novemberCells['2026-11-03']['is_today']);
        $this->assertSame("8:00 AM \u{2013} 5:00 PM", $novemberCells['2026-11-03']['hours_label']);

        $this->assertSame('custom_hours', $novemberCells['2026-11-07']['state']);
        $this->assertSame('Enrollment Saturday', $novemberCells['2026-11-07']['label']);
        $this->assertSame("9:00 AM \u{2013} 3:00 PM", $novemberCells['2026-11-07']['hours_label']);

        $this->assertSame('closed', $novemberCells['2026-11-08']['state']);
        $this->assertSame('Closed', $novemberCells['2026-11-08']['hours_label']);
    }

    public function test_accounting_configures_cashier_schedule_and_independent_registrar_holiday_hints(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'Asia/Manila'));

        $accounting = $this->accountingUser();
        $package = TermCalendarPackage::factory()->create([
            'state' => TermCalendarPackage::StateActive,
            'recorded_by' => $accounting->id,
        ]);
        TermDatedException::factory()->create([
            'term_calendar_package_id' => $package->id,
            'starts_on' => '2026-11-30',
            'ends_on' => '2026-11-30',
            'exception_type' => 'Holiday',
            'label' => 'Bonifacio Day',
        ]);

        // Registrar holiday alone never closes Campus Cashier automatically.
        $beforeStatus = CashierSchedule::todayStatus(
            app(SchoolPaymentChannels::class)->current()['cash']['schedule'],
            CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Manila'),
        );
        $this->assertSame('open_now', $beforeStatus['status']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($accounting)
            ->test(PaymentChannels::class)
            ->call('startEditing')
            ->assertSee('Registrar academic calendar reference')
            ->assertSee('Bonifacio Day')
            ->assertSee('+ Add to cashier closures')
            ->assertSeeHtml('payment-channel-card--collapsed')
            ->assertSeeHtml('payment-channel-card--active')
            ->assertSeeHtml('payment-channels-weekly-row--closed')
            ->assertSeeHtml('payment-channels-weekly-row__closed-badge')
            ->assertDontSeeHtml('id="payment-channels-summary-heading" role="status"');

        $page->set('data.cash.schedule.weekly.1.open', true)
            ->set('data.cash.schedule.weekly.1.opens_at', '08:30')
            ->set('data.cash.schedule.weekly.1.closes_at', '16:30')
            ->set('data.cash.schedule.weekly.6.open', true)
            ->set('data.cash.schedule.weekly.6.opens_at', '08:00')
            ->set('data.cash.schedule.weekly.6.closes_at', '12:00')
            ->set('data.cash.schedule.cutoff_note', '12:00 PM – 1:00 PM lunch break')
            ->callAction(TestAction::make('copyMondayToWeekdays')->schemaComponent())
            ->assertSet('data.cash.schedule.weekly.2.open', true)
            ->assertSet('data.cash.schedule.weekly.2.opens_at', '08:30')
            ->assertSet('data.cash.schedule.weekly.2.closes_at', '16:30')
            ->assertSet('data.cash.schedule.weekly.5.opens_at', '08:30')
            ->assertSet('data.cash.schedule.weekly.5.closes_at', '16:30')
            ->assertSee("Mon\u{2013}Fri")
            ->assertSee("8:30 AM \u{2013} 4:30 PM")
            ->assertSee("8:00 AM \u{2013} 12:00 PM")
            ->assertSee('12:00 PM – 1:00 PM lunch break');

        $page->set('data.cash.schedule.exceptions.custom-sat', [
            'starts_on' => '2026-11-14',
            'ends_on' => '2026-11-14',
            'status' => CashierSchedule::StatusCustomHours,
            'opens_at' => '09:00',
            'closes_at' => '13:00',
            'label' => 'Enrollment Saturday',
        ])
            ->assertSee('Enrollment Saturday')
            ->assertSee("9:00 AM \u{2013} 1:00 PM");

        // Adding the hint updates the draft/live preview cleanly without persisting a closure until saved.
        $page->call('addRegistrarHolidayHint', '2026-11-30', '2026-11-30', 'Bonifacio Day')
            ->assertSee('Added')
            ->assertSee('Bonifacio Day');

        $stillOpenBeforeSave = CashierSchedule::todayStatus(
            app(SchoolPaymentChannels::class)->current()['cash']['schedule'],
            CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Manila'),
        );
        $this->assertSame('open_now', $stillOpenBeforeSave['status']);

        $page->call('save')
            ->assertHasNoErrors()
            ->assertNotified('Payment channels saved');

        $savedCash = app(SchoolPaymentChannels::class)->current()['cash'];
        $this->assertSame(
            "Mon\u{2013}Fri, 8:30 AM \u{2013} 4:30 PM \u{00B7} Sat, 8:00 AM \u{2013} 12:00 PM",
            $savedCash['hours'],
        );
        $this->assertSame('12:00 PM – 1:00 PM lunch break', $savedCash['schedule']['cutoff_note']);
        $this->assertSame('08:30', $savedCash['schedule']['weekly'][2]['opens_at']);
        $this->assertSame('16:30', $savedCash['schedule']['weekly'][2]['closes_at']);
        $this->assertCount(2, $savedCash['schedule']['exceptions']);

        $closedAfterSave = CashierSchedule::todayStatus(
            $savedCash['schedule'],
            CarbonImmutable::parse('2026-11-30 10:00:00', 'Asia/Manila'),
        );
        $this->assertSame('closed_today', $closedAfterSave['status']);
        $this->assertSame('Bonifacio Day', $closedAfterSave['exception_label']);
    }

    public function test_where_to_pay_renders_page_and_compact_modal_variants_across_learner_surfaces(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00', 'Asia/Manila'));

        $accounting = $this->accountingUser();
        $schedule = CashierSchedule::defaultSchedule();
        $schedule['exceptions'] = [
            [
                'starts_on' => '2026-11-30',
                'ends_on' => '2026-11-30',
                'status' => CashierSchedule::StatusClosed,
                'label' => 'Bonifacio Day',
            ],
        ];

        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [[
                    'id' => 'bank-landbank-modal',
                    'bank_name' => 'Land Bank of the Philippines',
                    'account_name' => 'TALA State College',
                    'account_number' => '0011-2233-44',
                    'instructions' => 'Use your student number as reference.',
                ]],
            ],
            'e_wallets' => [
                'enabled' => true,
                'accounts' => [[
                    'id' => 'ewallet-gcash-modal',
                    'provider' => 'GCash',
                    'account_name' => 'TALA Official Cashier',
                    'account_number' => '09171234567',
                    'instructions' => 'Attach your GCash receipt.',
                ]],
            ],
            'cash' => [
                'enabled' => true,
                'location' => 'Finance Window 1, Ground Floor',
                'schedule' => $schedule,
            ],
        ], $accounting, 'Configure electronic and cashier channels for learner views');

        $studentFixture = $this->unpaidAccountFixture(isStudent: true);
        $applicantFixture = $this->unpaidAccountFixture(isStudent: false);

        Filament::setCurrentPanel(Filament::getPanel('student'));

        $financePage = Livewire::actingAs($studentFixture['learner'])
            ->test(StudentFinance::class)
            ->assertSee('Submit receipt')
            ->assertSee('Onsite · No upload')
            ->assertSee('Open now')
            ->assertSee('Open today until 5:00 PM')
            ->assertSee('Cashier monthly operating calendar')
            ->assertSee('Special hours')
            ->assertDontSee('Modified / Holiday')
            ->assertSee('November 2026')
            ->assertSee('Bonifacio Day')
            ->assertDontSee('upload your receipt here');

        $financePage->mountAction('submitEvidence')
            ->assertActionMounted('submitEvidence')
            ->assertMountedActionModalSee('Send your payment to an enabled bank or e-wallet account below, then attach your receipt in the form.')
            ->assertMountedActionModalSee('Land Bank of the Philippines')
            ->assertMountedActionModalSee('0011-2233-44')
            ->assertMountedActionModalSee('09171234567')
            ->assertMountedActionModalSee('Copy')
            ->assertMountedActionModalSeeHtml('data-copy-reference="0011-2233-44"')
            ->assertMountedActionModalSeeHtml('data-copy-reference="09171234567"')
            ->assertMountedActionModalSee('Open now')
            ->assertMountedActionModalSee('Finance Window 1, Ground Floor')
            ->assertMountedActionModalSee('Paying in person? Campus Cashier records onsite payments directly: no receipt upload needed.')
            ->assertMountedActionModalDontSee('Cashier monthly operating calendar')
            ->assertMountedActionModalDontSee('Onsite · No upload')
            ->call('unmountAction');

        Livewire::actingAs($studentFixture['learner'])
            ->test(StudentEnrollment::class)
            ->assertSee('Submit receipt')
            ->assertSee('Onsite · No upload')
            ->assertSee('Open now')
            ->assertSee('Cashier monthly operating calendar')
            ->assertDontSee('upload your receipt here');

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicantFixture['learner'])
            ->test(ApplicantEnrollment::class)
            ->assertSee('Submit receipt')
            ->assertSee('Onsite · No upload')
            ->assertSee('Open now')
            ->assertSee('Cashier monthly operating calendar')
            ->assertDontSee('upload your receipt here');

        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => ['enabled' => false, 'accounts' => []],
            'e_wallets' => ['enabled' => false, 'accounts' => []],
            'cash' => [
                'enabled' => true,
                'location' => 'Finance Window 1, Ground Floor',
                'schedule' => $schedule,
            ],
        ], $accounting, 'Switch to onsite cashier only');

        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::actingAs($studentFixture['learner'])
            ->test(StudentFinance::class)
            ->assertActionHidden('submitEvidence')
            ->assertSee('Electronic transfer channels are currently unavailable')
            ->assertSee('Pay onsite at the Campus Cashier below');

        Livewire::actingAs($studentFixture['learner'])
            ->test(StudentEnrollment::class)
            ->assertActionHidden('submitPaymentEvidence')
            ->assertSee('onsite at the Campus Cashier below: electronic transfer channels are currently unavailable')
            ->assertSee('Onsite Cashier payments are recorded directly: no receipt upload needed.');
    }

    public function test_holiday_repeater_item_labels_preserve_context_for_collapsed_rows(): void
    {
        $this->assertSame(
            'New date exception · Closed',
            CashierSchedule::formatExceptionItemLabel([])
        );

        $this->assertSame(
            "All Saints' Day · Nov 1, 2026 · Closed",
            CashierSchedule::formatExceptionItemLabel([
                'starts_on' => '2026-11-01',
                'ends_on' => null,
                'status' => CashierSchedule::StatusClosed,
                'label' => "All Saints' Day",
            ])
        );

        $this->assertSame(
            'Holy Week Break · Apr 2–4, 2026 · Closed',
            CashierSchedule::formatExceptionItemLabel([
                'starts_on' => '2026-04-02',
                'ends_on' => '2026-04-04',
                'status' => CashierSchedule::StatusClosed,
                'label' => 'Holy Week Break',
            ])
        );

        $this->assertSame(
            'Enrollment Saturday · Nov 7, 2026 · 8:00 AM – 12:00 PM',
            CashierSchedule::formatExceptionItemLabel([
                'starts_on' => '2026-11-07',
                'ends_on' => '2026-11-07',
                'status' => CashierSchedule::StatusCustomHours,
                'opens_at' => '08:00',
                'closes_at' => '12:00',
                'label' => 'Enrollment Saturday',
            ])
        );

        $accounting = $this->accountingUser();
        $schedule = CashierSchedule::defaultSchedule();
        $schedule['exceptions'] = [
            [
                'starts_on' => '2026-11-07',
                'ends_on' => '2026-11-07',
                'status' => CashierSchedule::StatusCustomHours,
                'opens_at' => '08:00',
                'closes_at' => '12:00',
                'label' => 'Enrollment Saturday',
            ],
        ];

        app(SchoolPaymentChannels::class)->update([
            'cash' => [
                'enabled' => true,
                'location' => 'Campus Cashier Window',
                'schedule' => $schedule,
            ],
        ], $accounting, 'Add holiday item label test');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($accounting)
            ->test(PaymentChannels::class)
            ->assertSee('View only')
            ->assertDontSeeHtml('fi-fo-repeater-item-header-label')
            ->call('startEditing')
            ->assertSeeHtml('fi-fo-repeater-item-header-label')
            ->assertSee('Enrollment Saturday · Nov 7, 2026 · 8:00 AM – 12:00 PM')
            ->call('cancelEditing')
            ->assertSet('isEditing', false)
            ->assertSee('View only');
    }

    public function test_save_confirmation_modal_blocks_invalid_enabled_channels_and_shows_specific_toast(): void
    {
        $accounting = $this->accountingUser();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($accounting)
            ->test(PaymentChannels::class)
            ->call('startEditing')
            ->set('data.e_wallets.enabled', true)
            ->assertActionVisible('saveChannels');

        $walletKey = (string) array_key_first($page->get('data.e_wallets.accounts'));

        $page->mountAction('saveChannels')
            ->assertActionNotMounted('saveChannels')
            ->assertHasErrors([
                "data.e_wallets.accounts.{$walletKey}.provider",
                "data.e_wallets.accounts.{$walletKey}.account_name",
                "data.e_wallets.accounts.{$walletKey}.account_number",
            ])
            ->assertNotified(SafeUserNotification::ValidationErrorTitle);

        $page->set("data.e_wallets.accounts.{$walletKey}.provider", 'GCash')
            ->set("data.e_wallets.accounts.{$walletKey}.account_name", 'Servitech Institute Asia Inc.')
            ->set("data.e_wallets.accounts.{$walletKey}.account_number", '0917 555 0199')
            ->mountAction('saveChannels')
            ->assertActionMounted('saveChannels');

        $description = $page->instance()->saveConfirmationDescription();
        $this->assertStringContainsString('Active channels:', $description);
        $this->assertStringContainsString('E-Wallets (1 account)', $description);

        $page->callMountedAction(['reason' => 'Added GCash'])
            ->assertHasNoErrors()
            ->assertSet('isEditing', false)
            ->assertNotified('Payment channels saved');
    }

    /** @return array{learner: User, enrollment: Enrollment, account: TermAccount, assessment: Assessment} */
    private function unpaidAccountFixture(bool $isStudent): array
    {
        $learner = User::factory()->create(['status' => User::StatusActive]);
        $roleName = $isStudent ? 'student' : 'applicant';
        Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $learner->assignRole($roleName);

        $term = Term::factory()->create();
        $profile = $isStudent ? StudentProfile::factory()->create(['user_id' => $learner->id]) : null;

        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile?->id,
            'credential_user_id' => $learner->id,
            'term_id' => $term->id,
        ]);
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $enrollment->id,
            'state' => RegistrationProposalVersion::StateConfirmed,
        ]);
        RegistrationProposalConfirmation::factory()->create([
            'registration_proposal_version_id' => $proposal->id,
            'learner_user_id' => $learner->id,
        ]);
        $enrollment->update(['current_proposal_version_id' => $proposal->id]);

        $account = TermAccount::factory()->create([
            'enrollment_id' => $enrollment->id,
            'credential_user_id' => $learner->id,
            'term_id' => $term->id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_account_id' => $account->id,
            'source_proposal_version_id' => $proposal->id,
            'total' => '48000.00',
            'subtotal' => '48000.00',
        ]);
        AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 1,
            'code' => 'ENROLLMENT',
            'label' => 'Enrollment obligation',
            'purpose' => 'Enrollment',
            'amount' => '12000.00',
            'due_at' => now()->subHour(),
            'required_for_enrollment' => true,
        ]);
        AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'sequence' => 2,
            'code' => 'FINAL',
            'label' => 'Final obligation',
            'purpose' => 'TermPayment',
            'amount' => '36000.00',
            'due_at' => now()->addMonths(2),
            'required_for_enrollment' => false,
        ]);

        return [
            'learner' => $learner,
            'enrollment' => $enrollment,
            'account' => $account,
            'assessment' => $assessment,
        ];
    }

    public function test_accounting_can_manage_multi_account_bank_and_ewallet_repeaters_with_ph_validation(): void
    {
        $accounting = $this->accountingUser();
        $channelsService = app(SchoolPaymentChannels::class);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::actingAs($accounting)
            ->test(PaymentChannels::class)
            ->set('data.bank_transfer.enabled', true)
            ->set('data.e_wallets.enabled', true);

        $firstBankKey = (string) array_key_first($page->get('data.bank_transfer.accounts'));
        $firstWalletKey = (string) array_key_first($page->get('data.e_wallets.accounts'));

        $page->set("data.bank_transfer.accounts.{$firstBankKey}", [
            'id' => (string) Str::uuid(),
            'bank_name' => 'BDO Unibank',
            'branch' => 'Calamba Branch',
            'account_name' => 'TALA Registrar Fund',
            'account_number' => '0012-3456-7890',
            'instructions' => 'Send transfer slip.',
        ])
            ->set("data.e_wallets.accounts.{$firstWalletKey}", [
                'id' => $page->get("data.e_wallets.accounts.{$firstWalletKey}.id") ?: (string) Str::uuid(),
                'provider' => 'GCash',
                'account_name' => 'TALA Cashier GCash',
                'account_number' => '0917 123 4567',
                'instructions' => 'Use Bills or Express Send.',
            ])
            ->callAction(TestAction::make('add')->schemaComponent('bank_transfer.accounts'))
            ->callAction(TestAction::make('add')->schemaComponent('bank_transfer.accounts'))
            ->callAction(TestAction::make('add')->schemaComponent('e_wallets.accounts'));

        $bankKeys = array_keys($page->get('data.bank_transfer.accounts'));
        $this->assertCount(3, $bankKeys);
        $secondBankKey = (string) $bankKeys[1];
        $thirdBankKey = (string) $bankKeys[2];

        $walletKeys = array_keys($page->get('data.e_wallets.accounts'));
        $this->assertCount(2, $walletKeys);
        $secondWalletKey = (string) $walletKeys[1];

        $page->set("data.bank_transfer.accounts.{$secondBankKey}", [
            'id' => (string) Str::uuid(),
            'bank_name' => 'Land Bank of the Philippines',
            'branch' => 'Talisay',
            'account_name' => 'TALA State Fund',
            'account_number' => '1092-3344-5566',
            'instructions' => 'State university main account.',
        ])
            ->set("data.e_wallets.accounts.{$secondWalletKey}", [
                'id' => (string) Str::uuid(),
                'provider' => 'Maya',
                'account_name' => 'TALA Cashier Maya',
                'account_number' => '+63 918 234 5678',
                'instructions' => 'Keep screenshot.',
            ])
            ->callAction(TestAction::make('delete')->schemaComponent('bank_transfer.accounts')->arguments(['item' => $thirdBankKey]))
            ->callAction(TestAction::make('moveUp')->schemaComponent('bank_transfer.accounts')->arguments(['item' => $secondBankKey]))
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $channelsService->current();
        $this->assertCount(2, $saved['bank_transfer']['accounts']);
        $this->assertSame('Land Bank of the Philippines', $saved['bank_transfer']['accounts'][0]['bank_name']);
        $this->assertSame('BDO Unibank', $saved['bank_transfer']['accounts'][1]['bank_name']);
        $this->assertTrue(Str::isUuid($saved['bank_transfer']['accounts'][0]['id']));
        $this->assertTrue(Str::isUuid($saved['bank_transfer']['accounts'][1]['id']));
        $this->assertCount(2, $saved['e_wallets']['accounts']);
        $this->assertSame(['GCash', 'Maya'], array_column($saved['e_wallets']['accounts'], 'provider'));

        $normalized = $channelsService->normalizeChannels([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [
                    ['id' => str_repeat('z', 80), 'bank_name' => 'BPI', 'account_name' => 'Acct 1', 'account_number' => '1234567890'],
                    ['id' => 'dup-id', 'bank_name' => 'BDO Unibank', 'account_name' => 'Acct 2', 'account_number' => '0987654321'],
                    ['id' => 'dup-id', 'bank_name' => 'Metrobank', 'account_name' => 'Acct 3', 'account_number' => '1122334455'],
                ],
            ],
            'e_wallets' => ['enabled' => false, 'accounts' => []],
            'cash' => ['enabled' => true, 'location' => 'Cashier Window 1'],
        ]);
        $this->assertSame('bank-1', $normalized['bank_transfer']['accounts'][0]['id']);
        $this->assertSame('dup-id', $normalized['bank_transfer']['accounts'][1]['id']);
        $this->assertSame('bank-2', $normalized['bank_transfer']['accounts'][2]['id']);

        foreach ([
            ['group' => 'bank_transfer', 'account' => ['bank_name' => 'BDO Unibank', 'account_name' => 'TALA', 'account_number' => '123456789'], 'key' => 'bank_transfer.accounts.0.account_number'],
            ['group' => 'e_wallets', 'account' => ['provider' => 'GCash', 'account_name' => 'TALA', 'account_number' => '091712345'], 'key' => 'e_wallets.accounts.0.account_number'],
            ['group' => 'e_wallets', 'account' => ['provider' => 'Maya', 'account_name' => 'TALA', 'account_number' => '999'], 'key' => 'e_wallets.accounts.0.account_number'],
            ['group' => 'e_wallets', 'account' => ['provider' => 'GoTyme Bank', 'account_name' => 'TALA', 'account_number' => '12345'], 'key' => 'e_wallets.accounts.0.account_number'],
        ] as $invalidCase) {
            try {
                $channelsService->update([
                    'bank_transfer' => [
                        'enabled' => $invalidCase['group'] === 'bank_transfer',
                        'accounts' => $invalidCase['group'] === 'bank_transfer' ? [$invalidCase['account']] : [],
                    ],
                    'e_wallets' => [
                        'enabled' => $invalidCase['group'] === 'e_wallets',
                        'accounts' => $invalidCase['group'] === 'e_wallets' ? [$invalidCase['account']] : [],
                    ],
                    'cash' => ['enabled' => true, 'location' => 'Cashier Window 1'],
                ], $accounting);
                $this->fail('Expected PH account validation failure for '.$invalidCase['key']);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($invalidCase['key'], $exception->errors());
            }
        }

        foreach (['bank_transfer', 'e_wallets'] as $emptyGroup) {
            try {
                $channelsService->update([
                    'bank_transfer' => ['enabled' => $emptyGroup === 'bank_transfer', 'accounts' => []],
                    'e_wallets' => ['enabled' => $emptyGroup === 'e_wallets', 'accounts' => []],
                    'cash' => ['enabled' => true, 'location' => 'Cashier Window 1'],
                ], $accounting);
                $this->fail('Expected empty active digital group failure for '.$emptyGroup);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey("{$emptyGroup}.accounts", $exception->errors());
            }
        }

        $channelsService->update([
            'bank_transfer' => ['enabled' => false, 'accounts' => []],
            'e_wallets' => [
                'enabled' => true,
                'accounts' => [
                    ['provider' => 'Maya', 'account_name' => 'TALA Maya Bank', 'account_number' => '102938475612'],
                    ['provider' => 'GoTyme Bank', 'account_name' => 'TALA GoTyme', 'account_number' => '019283746510'],
                ],
            ],
            'cash' => ['enabled' => true, 'location' => 'Cashier Window 1'],
        ], $accounting);
        $this->assertCount(2, $channelsService->current()['e_wallets']['accounts']);
    }

    public function test_legacy_flat_bank_transfer_and_gcash_settings_auto_migrate_to_multi_account_shape(): void
    {
        $accounting = $this->accountingUser();
        $channelsService = app(SchoolPaymentChannels::class);

        $legacyPayload = [
            'bank_transfer' => [
                'enabled' => true,
                'bank_name' => 'Bank of the Philippine Islands (BPI)',
                'branch' => 'Talisay City',
                'account_name' => 'TALA Foundation',
                'account_number' => '3120-0045-1829',
                'instructions' => 'Include applicant number.',
            ],
            'gcash' => [
                'enabled' => true,
                'account_name' => 'TALA Treasury',
                'account_number' => '0917 555 0199',
                'instructions' => 'Upload Instapay receipt.',
            ],
            'cash' => [
                'enabled' => true,
                'location' => 'Cashier Window 1',
                'instructions' => 'Bring assessment slip.',
            ],
        ];

        $normalized = $channelsService->normalizeChannels($legacyPayload);
        $this->assertSame('legacy-bank-1', $normalized['bank_transfer']['accounts'][0]['id']);
        $this->assertSame('Bank of the Philippine Islands (BPI)', $normalized['bank_transfer']['accounts'][0]['bank_name']);
        $this->assertSame('legacy-gcash-1', $normalized['e_wallets']['accounts'][0]['id']);
        $this->assertSame('GCash', $normalized['e_wallets']['accounts'][0]['provider']);

        SystemSetting::query()
            ->where('key', SchoolPaymentChannels::SettingKey)
            ->where('scope_type', 'institution')
            ->where('scope_id', 0)
            ->where('status', 'Active')
            ->update(['status' => 'Superseded', 'effective_until' => now()]);

        (new SystemSetting)->forceFill([
            'key' => SchoolPaymentChannels::SettingKey,
            'scope_type' => 'institution',
            'scope_id' => 0,
            'value_type' => SystemSetting::ValueTypeJson,
            'value' => json_encode($legacyPayload, JSON_THROW_ON_ERROR),
            'effective_from' => now(),
            'version' => ((int) SystemSetting::query()->where('key', SchoolPaymentChannels::SettingKey)->max('version')) + 1,
            'status' => 'Active',
            'changed_by' => $accounting->id,
            'reason' => 'Seed legacy v1 payment channel JSON for lazy auto-migration test.',
        ])->save();

        $current = $channelsService->current();
        $this->assertSame('legacy-bank-1', $current['bank_transfer']['accounts'][0]['id']);
        $this->assertSame('legacy-gcash-1', $current['e_wallets']['accounts'][0]['id']);

        $bankSnapshot = $channelsService->resolveAccountSnapshot('bank_transfer');
        $this->assertNotNull($bankSnapshot);
        $this->assertSame('legacy-bank-1', $bankSnapshot['account_id']);
        $this->assertSame('Bank of the Philippine Islands (BPI)', $bankSnapshot['provider_or_bank']);

        $gcashSnapshot = $channelsService->resolveAccountSnapshot('gcash_manual');
        $this->assertNotNull($gcashSnapshot);
        $this->assertSame('legacy-gcash-1', $gcashSnapshot['account_id']);
        $this->assertSame('GCash', $gcashSnapshot['provider_or_bank']);
    }

    public function test_digital_upload_guard_blocks_evidence_submission_when_only_cash_is_enabled(): void
    {
        $accounting = $this->accountingUser();
        $channelsService = app(SchoolPaymentChannels::class);
        $fixture = $this->unpaidAccountFixture(true);

        $channelsService->update([
            'bank_transfer' => ['enabled' => false, 'accounts' => []],
            'e_wallets' => ['enabled' => false, 'accounts' => []],
            'cash' => ['enabled' => true, 'location' => 'Cashier Window 1', 'instructions' => 'Pay onsite only.'],
        ], $accounting);

        $this->assertFalse($channelsService->hasEnabledDigitalChannels());
        Storage::fake('local');
        $validPdf = UploadedFile::fake()->createWithContent('onsite-blocked.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        try {
            app(SubmitPaymentEvidence::class)->execute(
                $fixture['account'],
                $fixture['learner'],
                $validPdf,
                '1500.00',
                'bank_transfer',
                now()->subHour(),
                'REF-GUARD-1',
            );
            $this->fail('Expected SubmitPaymentEvidence to reject uploads when only Cash is active.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('channel', $exception->errors());
        }

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($fixture['learner'])
            ->test(StudentFinance::class)
            ->assertActionHidden('submitEvidence')
            ->assertSee('Electronic transfer channels are currently unavailable. Pay onsite at the Campus Cashier below; onsite payments are recorded directly without uploading a receipt.');

        Livewire::actingAs($fixture['learner'])
            ->test(StudentEnrollment::class)
            ->assertActionHidden('submitPaymentEvidence');
    }

    public function test_multi_account_student_receipt_submission_persists_channel_snapshot_through_payment_and_export(): void
    {
        $accounting = $this->accountingUser();
        $channelsService = app(SchoolPaymentChannels::class);
        $gcashId = (string) Str::uuid();
        $mayaId = (string) Str::uuid();
        $bdoId = (string) Str::uuid();

        $channelsService->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [
                    ['id' => $bdoId, 'bank_name' => 'BDO Unibank', 'branch' => 'Calamba', 'account_name' => 'TALA Fund', 'account_number' => '0012-3456-7890'],
                ],
            ],
            'e_wallets' => [
                'enabled' => true,
                'accounts' => [
                    ['id' => $gcashId, 'provider' => 'GCash', 'account_name' => 'TALA GCash', 'account_number' => '0917 111 2222'],
                    ['id' => $mayaId, 'provider' => 'Maya', 'account_name' => 'TALA Maya', 'account_number' => '0918 123 4567'],
                ],
            ],
            'cash' => ['enabled' => true, 'location' => 'Cashier Window 1'],
        ], $accounting);

        $fixture = $this->unpaidAccountFixture(true);
        Storage::fake('local');
        $pdf = UploadedFile::fake()->createWithContent('maya-receipt.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($fixture['learner'])
            ->test(StudentFinance::class)
            ->callAction('submitEvidence', data: [
                'channel' => 'e_wallets',
                'channel_account_id' => $mayaId,
                'payment_reference' => 'MAYA-MULTI-001',
                'claimed_amount' => '1500.00',
                'paid_at' => '2026-10-08 10:15:00',
                'evidence' => $pdf,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Receipt submitted for Accounting review');

        $evidence = PaymentEvidenceVersion::query()
            ->where('term_account_id', $fixture['account']->id)
            ->latest('version')
            ->firstOrFail();

        $this->assertSame('e_wallet', $evidence->channel);
        $this->assertSame('e_wallets', $evidence->channel_snapshot['group'] ?? null);
        $this->assertSame($mayaId, $evidence->channel_snapshot['account_id'] ?? null);
        $this->assertSame('Maya', $evidence->channel_snapshot['provider_or_bank'] ?? null);
        $this->assertSame('0918 123 4567', $evidence->channel_snapshot['account_number'] ?? null);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->callAction('verifyPaymentEvidence', data: [
                'evidence_id' => $evidence->id,
                'actual_verified_amount' => '1500.00',
                'external_check_reference' => 'MAYA-SETTLE-001',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Payment receipt verified');

        $payment = Payment::query()
            ->where('term_account_id', $fixture['account']->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($mayaId, $payment->channel_snapshot['account_id'] ?? null);
        $this->assertSame('E-Wallet — Maya (0918 123 4567)', $payment->channelDetailLabel());

        /** @var FinanceExport $export */
        $export = app(CreateContextualFinanceExport::class)->createVerifiedPayments(
            $accounting,
            $fixture['account']->fresh(),
            CreateContextualFinanceExport::VerifiedPaymentsPurpose,
            [],
        );

        $csvLines = preg_split("/\r\n|\n|\r/", trim((string) Storage::disk($export->disk)->get($export->path))) ?: [];
        $headers = str_getcsv($csvLines[0]);
        $row = array_combine($headers, str_getcsv($csvLines[1]));
        $this->assertSame('E-Wallet — Maya (0918 123 4567)', $row['Channel']);
    }

    public function test_submit_payment_evidence_rejects_spoofed_png_and_pdf_magic_bytes(): void
    {
        $accounting = $this->accountingUser();
        $this->enableDefaultDigitalChannels($accounting);
        $fixture = $this->unpaidAccountFixture(true);
        Storage::fake('local');

        $spoofedPng = UploadedFile::fake()->createWithContent('spoof.png', 'NOT_A_PNG_HEADER');
        $spoofedPdf = UploadedFile::fake()->createWithContent('spoof.pdf', '<html>fake pdf</html>');

        foreach ([$spoofedPng, $spoofedPdf] as $spoofedFile) {
            try {
                app(SubmitPaymentEvidence::class)->execute(
                    $fixture['account'],
                    $fixture['learner'],
                    $spoofedFile,
                    '1500.00',
                    'bank_transfer',
                    now()->subHour(),
                    'SPOOF-REF-1',
                );
                $this->fail('Expected magic-byte validation failure for '.$spoofedFile->getClientOriginalName());
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
                $this->assertSame(
                    ['The uploaded receipt file does not match a valid PDF, JPEG, or PNG signature.'],
                    $exception->errors()['file'],
                );
            }
        }

        $validPdf = UploadedFile::fake()->createWithContent('valid-receipt.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $evidence = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'],
            $fixture['learner'],
            $validPdf,
            '1500.00',
            'bank_transfer',
            now()->subHour(),
            'VALID-REF-1',
        );

        $this->assertSame(PaymentEvidenceVersion::StateSubmitted, $evidence->state);
    }

    public function test_payment_channel_copy_buttons_and_document_viewer_render_accessible_markup(): void
    {
        $accounting = $this->accountingUser();
        $bdoFirstId = (string) Str::uuid();
        $channelsService = app(SchoolPaymentChannels::class);
        $channelsService->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [
                    ['id' => $bdoFirstId, 'bank_name' => 'BDO Unibank', 'branch' => null, 'account_name' => 'TALA Main', 'account_number' => '0012-3456-1111'],
                    ['bank_name' => 'BDO Unibank', 'branch' => null, 'account_name' => 'TALA Reserve', 'account_number' => '0012-3456-2222'],
                    ['bank_name' => 'Land Bank of the Philippines', 'branch' => 'Talisay', 'account_name' => 'TALA SU', 'account_number' => '1092-3344-5566'],
                ],
            ],
            'e_wallets' => [
                'enabled' => true,
                'accounts' => [
                    ['provider' => 'Maya', 'account_name' => 'TALA Maya', 'account_number' => '0918 123 4567'],
                ],
            ],
            'cash' => ['enabled' => true, 'location' => 'Cashier Window 1'],
        ], $accounting);

        $fixture = $this->unpaidAccountFixture(true);
        Storage::fake('local');
        $pdf = UploadedFile::fake()->createWithContent('viewer-check.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $evidence = app(SubmitPaymentEvidence::class)->execute(
            $fixture['account'],
            $fixture['learner'],
            $pdf,
            '1500.00',
            'bank_transfer',
            now()->subHour(),
            'VIEWER-REF-1',
            null,
            $channelsService->resolveAccountSnapshot('bank_transfer', $bdoFirstId),
        );

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($fixture['learner'])
            ->test(StudentFinance::class)
            ->assertSeeHtml('Copy<span class="sr-only"> BDO Unibank ending 1111 account number</span>')
            ->assertSeeHtml('Copy<span class="sr-only"> BDO Unibank ending 2222 account number</span>')
            ->assertSeeHtml('Copy<span class="sr-only"> Land Bank of the Philippines (Talisay) account number</span>')
            ->assertSeeHtml('Copy<span class="sr-only"> Maya mobile / account number</span>')
            ->assertSeeHtml('tala-document-viewer-modal')
            ->assertDontSee('@js(', false)
            ->assertSeeHtml('data-modal-id=')
            ->assertSee(route('finance.payment-evidence.view', $evidence), false)
            ->assertSee('Download receipt');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($accounting)
            ->test(ViewEnrollment::class, ['record' => $fixture['enrollment']->id])
            ->assertSeeHtml('tala-document-viewer-modal')
            ->assertDontSee('@js(', false)
            ->assertSee('Bank Transfer — BDO Unibank (0012-3456-1111)')
            ->assertSee(route('finance.payment-evidence.view', $evidence), false)
            ->assertSee('Download receipt');

        $inlineHtml = Blade::render(
            '<x-document-viewer id="inline-doc-check" :view-url="$viewUrl" :download-url="$downloadUrl" :inline="true" :show-inline-download="true" />',
            [
                'viewUrl' => route('finance.payment-evidence.view', $evidence),
                'downloadUrl' => route('finance.payment-evidence.download', $evidence),
            ],
        );

        $this->assertStringContainsString('tala-document-viewer-inline', $inlineHtml);
        $this->assertStringContainsString('data-payment-proof-preview', $inlineHtml);
        $this->assertStringContainsString('Download receipt', $inlineHtml);
    }

    private function enableDefaultDigitalChannels(User $accounting): void
    {
        app(SchoolPaymentChannels::class)->update([
            'bank_transfer' => [
                'enabled' => true,
                'accounts' => [
                    [
                        'bank_name' => 'Land Bank of the Philippines',
                        'branch' => 'Talisay',
                        'account_name' => 'TALA State University',
                        'account_number' => '0011-2233-44',
                        'instructions' => 'Upload bank transfer slip.',
                    ],
                ],
            ],
            'e_wallets' => [
                'enabled' => true,
                'accounts' => [
                    [
                        'provider' => 'GCash',
                        'account_name' => 'TALA State University Cashier',
                        'account_number' => '0917 123 4567',
                        'instructions' => 'Upload GCash screenshot.',
                    ],
                ],
            ],
            'cash' => [
                'enabled' => true,
                'location' => 'Cashier Window 1, Administration Building',
                'instructions' => 'Present your enrollment reference at the window.',
            ],
        ], $accounting);
    }

    private function accountingUser(): User
    {
        return $this->userWithRole(User::StaffRoleAccounting);
    }

    private function userWithRole(string $roleName): User
    {
        Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($roleName);

        return $user;
    }
}
