<?php

namespace Tests\Feature;

use App\Actions\Applicants\AdmissionWindowService;
use App\Actions\Applicants\ApplicantIntakeService;
use App\Actions\Applicants\WithdrawApplicantIntake;
use App\Filament\Applicant\Pages\Auth\RegisterApplicant;
use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Applicant\Pages\Requirements;
use App\Filament\Resources\ApplicantIntakes\Pages\ListApplicantIntakes;
use App\Filament\Resources\ApplicantIntakes\Pages\ViewApplicantIntake;
use App\Models\ApplicantIntake;
use App\Models\CalendarEvent;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5BAdmissionsWindowAndWithdrawalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));

        foreach (['applicant', User::StaffRoleRegistrar] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Permission::findOrCreate('approve-documents', 'web');
        CarbonImmutable::setTestNow('2026-07-26 10:00:00');
        $this->clearAdmissionWindows();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_admissions_window_is_fail_closed_until_an_active_current_window_exists(): void
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $service = app(AdmissionWindowService::class);

        $this->assertFalse($service->hasOpenAdmissionsWindow());
        $this->assertFalse($service->isAdmissionsWindowOpenForTerm($term->id));

        $future = $this->admissionsWindow($term, [
            'start_at' => now()->addDay(),
            'end_at' => now()->addDays(5),
        ]);
        $this->assertFalse($service->isAdmissionsWindowOpenForTerm($term->id));

        $future->forceFill([
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'state' => CalendarEvent::StateInactive,
        ])->save();
        $this->assertFalse($service->isAdmissionsWindowOpenForTerm($term->id));

        $future->forceFill(['state' => CalendarEvent::StateActive])->save();

        $this->assertTrue($service->hasOpenAdmissionsWindow());
        $this->assertTrue($service->isAdmissionsWindowOpenForTerm($term->id));
        $this->assertSame($future->id, $service->admissionsWindow($term->id)->id);
    }

    public function test_public_landing_and_direct_registration_reflect_admissions_availability(): void
    {
        $registrationUrl = route('filament.applicant.auth.register');

        $this->get('/')
            ->assertOk()
            ->assertSee('Applications are currently closed')
            ->assertDontSee('href="'.$registrationUrl.'"', false)
            ->assertSee(route('filament.applicant.auth.login'), false);

        $this->get($registrationUrl)
            ->assertRedirect(url('/?admissions=closed'));

        $term = Term::factory()->create(['state' => Term::StateActive]);
        $this->admissionsWindow($term);

        $this->get('/')
            ->assertOk()
            ->assertSee('Apply Online')
            ->assertSee('href="'.$registrationUrl.'"', false);

        $this->get($registrationUrl)
            ->assertOk()
            ->assertSee('Create Applicant Account');
    }

    public function test_registration_action_rechecks_the_window_before_creating_an_account(): void
    {
        $page = app(RegisterApplicant::class);
        $method = new ReflectionMethod(RegisterApplicant::class, 'handleRegistration');
        $method->setAccessible(true);

        try {
            $method->invoke($page, [
                'name' => 'Closed Window Applicant',
                'email' => 'closed-window-applicant@example.test',
                'password' => 'secret-password',
            ]);
            $this->fail('Expected closed admissions to block account creation.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $actual = $exception->getPrevious() ?? $exception;

            $this->assertInstanceOf(ValidationException::class, $actual);
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'closed-window-applicant@example.test',
        ]);
    }

    public function test_new_intake_and_final_submission_require_the_selected_terms_admissions_window(): void
    {
        $applicant = $this->applicant();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $service = app(ApplicantIntakeService::class);

        try {
            $service->saveDraft($applicant, $this->completeDraftData($term, $program));
            $this->fail('Expected new intake creation to fail outside admissions.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term_id', $exception->errors());
        }

        $this->assertDatabaseMissing('applicant_intakes', ['user_id' => $applicant->id]);

        $draft = ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
        ]);

        try {
            $service->submit($draft, true);
            $this->fail('Expected final submission to fail outside admissions.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term_id', $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusDraft, $draft->fresh()->status);
        $this->assertDatabaseMissing('checklist_items', ['applicant_intake_id' => $draft->id]);
    }

    public function test_an_existing_draft_remains_editable_after_its_admissions_window_closes(): void
    {
        $applicant = $this->applicant();
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
        ]);

        $saved = app(ApplicantIntakeService::class)->saveDraft(
            $applicant,
            [
                ...$this->completeDraftData($term, $program),
                'address_city' => 'Updated Synthetic City',
            ],
        );

        $this->assertSame(ApplicantIntake::StatusDraft, $saved->status);
        $this->assertSame('Updated Synthetic City', $saved->address_city);
    }

    public function test_withdrawal_requires_a_reason_and_records_one_atomic_terminal_transition(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);
        $service = app(WithdrawApplicantIntake::class);

        try {
            $service->execute($intake, $applicant, '   ');
            $this->fail('Expected an empty withdrawal reason to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusPending, $intake->fresh()->status);
        $this->assertSame(User::StatusApplicantPending, $applicant->fresh()->status);

        $withdrawn = $service->execute(
            $intake->fresh(),
            $applicant->fresh(),
            'I will continue my application directly with the Registrar.',
        );

        $activity = DB::table('activity_log')
            ->where('subject_type', ApplicantIntake::class)
            ->where('subject_id', $intake->id)
            ->where('event', 'applicant_intake_withdrawn')
            ->sole();
        $properties = json_decode((string) $activity->properties, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(ApplicantIntake::StatusWithdrawn, $withdrawn->status);
        $this->assertNotNull($withdrawn->archived_at);
        $this->assertSame(User::StatusApplicantWithdrawn, $applicant->fresh()->status);
        $this->assertSame(
            'I will continue my application directly with the Registrar.',
            $properties['reason'],
        );
        $this->assertSame($applicant->id, (int) $activity->causer_id);
    }

    public function test_reviewed_intake_cannot_be_withdrawn_and_keeps_its_existing_state(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusPending,
            'reviewed_at' => now(),
            'reviewed_by' => $this->registrar()->id,
        ]);

        try {
            app(WithdrawApplicantIntake::class)->execute(
                $intake,
                $applicant,
                'I no longer want to continue.',
            );
            $this->fail('Expected a reviewed intake to reject self-service withdrawal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(ApplicantIntake::StatusPending, $intake->fresh()->status);
        $this->assertNull($intake->fresh()->archived_at);
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => ApplicantIntake::class,
            'subject_id' => $intake->id,
            'event' => 'applicant_intake_withdrawn',
        ]);
    }

    public function test_withdrawn_state_is_truthful_for_applicant_and_registrar_surfaces(): void
    {
        $applicant = $this->applicant();
        $intake = ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
        ]);
        $reason = 'I need the Registrar to correct my admission term.';
        app(WithdrawApplicantIntake::class)->execute($intake, $applicant, $reason);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant->fresh())
            ->test(Dashboard::class)
            ->assertSee('No active application')
            ->assertSee('Application History')
            ->assertSee('Withdrawn before submission')
            ->assertDontSee($reason);

        Livewire::actingAs($applicant->fresh())
            ->test(Requirements::class)
            ->assertSee('Withdrawn before submission')
            ->assertSee($reason)
            ->assertDontSee('Your application has been submitted');

        $registrar = $this->registrar();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($registrar)
            ->test(ViewApplicantIntake::class, ['record' => $intake->id])
            ->assertSee('Withdrawal Details')
            ->assertSee($reason)
            ->assertSee($applicant->email);

        Livewire::actingAs($registrar)
            ->test(ListApplicantIntakes::class)
            ->set('activeTab', 'completed_history')
            ->assertCanSeeTableRecords([$intake->fresh()])
            ->assertSee('Withdrawn')
            ->assertDontSee($reason);
    }

    private function clearAdmissionWindows(): void
    {
        CalendarEvent::query()
            ->where('process_key', 'admissions')
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function admissionsWindow(Term $term, array $overrides = []): CalendarEvent
    {
        return CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => 'admissions',
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'blocks_scheduling' => false,
            'state' => CalendarEvent::StateActive,
            'authority' => 'TAL-96D5B acceptance test',
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function completeDraftData(Term $term, Program $program): array
    {
        return [
            'term_id' => $term->id,
            'program_id' => $program->id,
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'first_name' => 'Synthetic',
            'last_name' => 'Applicant',
            'birth_date' => '2005-05-10',
            'gender' => 'FEMALE',
            'civil_status' => 'SINGLE',
            'birth_place' => 'San Pedro, Laguna',
            'email' => 'synthetic-applicant@example.test',
            'phone' => '09123456789',
            'address_barangay' => 'Bagong Silang',
            'address_street' => '70 Madrigal Compound',
            'address_city' => 'San Pedro',
            'address_province' => 'Laguna',
            'prior_school' => 'Sample Senior High School',
            'guardian_name' => 'Synthetic Guardian',
            'guardian_phone' => '09987654321',
            'guardian_address' => 'San Pedro, Laguna',
        ];
    }

    private function applicant(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusApplicantPending,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('applicant');

        return $user;
    }

    private function registrar(): User
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole(User::StaffRoleRegistrar);
        $user->givePermissionTo('approve-documents');

        return $user;
    }
}
