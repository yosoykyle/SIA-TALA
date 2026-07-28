<?php

namespace Tests\Feature;

use App\Actions\Applicants\ApplicantIntakeService;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Applicant\Pages\Requirements;
use App\Models\ApplicantIntake;
use App\Models\CalendarEvent;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5BApplicantLifecycleHistoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('test_tala_db', config('database.connections.mysql.database'));

        Role::findOrCreate('applicant', 'web');
        CalendarEvent::query()->where('process_key', 'admissions')->delete();
    }

    public function test_applicant_account_exposes_all_intakes_and_resolves_the_current_nonterminal_intake(): void
    {
        $applicant = $this->applicant();
        $withdrawn = ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusWithdrawn,
            'archived_at' => now()->subMonth(),
        ]);
        $current = ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
        ]);

        $this->assertCount(2, $applicant->applicantIntakes);
        $this->assertTrue($applicant->currentApplicantIntake->is($current));
        $this->assertTrue($applicant->applicantIntake->is($current));
        $this->assertTrue($applicant->applicantIntakes->contains($withdrawn));
    }

    public function test_withdrawn_applicant_can_start_a_different_open_term_and_account_becomes_active_again(): void
    {
        $applicant = $this->applicant(User::StatusApplicantWithdrawn);
        $previousTerm = Term::factory()->create(['state' => Term::StateClosed]);
        $openTerm = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'term_id' => $previousTerm->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusWithdrawn,
            'archived_at' => now()->subMonth(),
        ]);
        $this->admissionsWindow($openTerm);

        $draft = app(ApplicantIntakeService::class)->saveDraft(
            $applicant,
            $this->completeDraftData($openTerm, $program),
        );

        $this->assertSame($openTerm->id, $draft->term_id);
        $this->assertSame(ApplicantIntake::StatusDraft, $draft->status);
        $this->assertSame(User::StatusApplicantPending, $applicant->fresh()->status);
        $this->assertSame(2, $applicant->applicantIntakes()->count());
    }

    public function test_same_term_retry_after_withdrawal_is_blocked_without_creating_another_intake(): void
    {
        $applicant = $this->applicant(User::StatusApplicantWithdrawn);
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'term_id' => $term->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusWithdrawn,
            'archived_at' => now()->subDay(),
        ]);
        $this->admissionsWindow($term);

        try {
            app(ApplicantIntakeService::class)->saveDraft(
                $applicant,
                $this->completeDraftData($term, $program),
            );
            $this->fail('Expected a same-term retry to require Registrar assistance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('term_id', $exception->errors());
            $this->assertStringContainsString('Registrar', $exception->errors()['term_id'][0]);
        }

        $this->assertSame(1, $applicant->applicantIntakes()->count());
    }

    public function test_an_existing_nonterminal_intake_blocks_another_active_application(): void
    {
        $applicant = $this->applicant();
        $firstTerm = Term::factory()->create(['state' => Term::StateActive]);
        $secondTerm = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        ApplicantIntake::factory()->create([
            'user_id' => $applicant->id,
            'term_id' => $firstTerm->id,
            'program_id' => $program->id,
            'status' => ApplicantIntake::StatusPending,
        ]);
        $this->admissionsWindow($secondTerm);

        try {
            app(ApplicantIntakeService::class)->saveDraft(
                $applicant,
                $this->completeDraftData($secondTerm, $program),
            );
            $this->fail('Expected an existing nonterminal application to block another intake.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(1, $applicant->applicantIntakes()->count());
    }

    public function test_database_contract_has_one_intake_per_applicant_and_term(): void
    {
        $index = collect(Schema::getIndexes('applicant_intakes'))
            ->first(fn (array $index): bool => $index['unique']
                && $index['columns'] === ['user_id', 'term_id']);

        $this->assertNotNull($index);
    }

    public function test_withdrawn_application_is_immutable_history(): void
    {
        $applicant = $this->applicant(User::StatusApplicantWithdrawn);
        $intake = ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusWithdrawn,
            'archived_at' => now()->subDay(),
        ]);
        $originalProgramId = $intake->program_id;

        try {
            $intake->forceFill(['program_id' => Program::factory()->create()->id])->save();
            $this->fail('Expected withdrawn history to reject mutation.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('immutable history', $exception->getMessage());
        }

        $this->assertSame($originalProgramId, $intake->fresh()->program_id);
    }

    public function test_dashboard_lists_only_the_authenticated_applicants_history_and_uses_truthful_terminal_copy(): void
    {
        $applicant = $this->applicant(User::StatusApplicantWithdrawn);
        $otherApplicant = $this->applicant(User::StatusApplicantWithdrawn);
        $withdrawn = ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusWithdrawn,
            'archived_at' => now()->subDay(),
        ]);
        $other = ApplicantIntake::factory()->draft()->create([
            'user_id' => $otherApplicant->id,
            'status' => ApplicantIntake::StatusWithdrawn,
            'archived_at' => now()->subDay(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Dashboard::class)
            ->assertCanSeeTableRecords([$withdrawn])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('No active application')
            ->assertSee('Application History')
            ->assertSee('Withdrawn before submission')
            ->assertDontSee('This submitted application');
    }

    public function test_requirements_explains_when_a_draft_was_withdrawn_before_submission(): void
    {
        $applicant = $this->applicant(User::StatusApplicantWithdrawn);
        ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'status' => ApplicantIntake::StatusWithdrawn,
            'archived_at' => now(),
            'submitted_at' => null,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Requirements::class)
            ->assertSee('Withdrawn before submission')
            ->assertSee('No Registrar checklist was created')
            ->assertDontSee('Your application has been submitted');
    }

    public function test_requirements_explains_the_empty_state_before_an_application_exists(): void
    {
        $applicant = $this->applicant();

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Requirements::class)
            ->assertSee('Admission Requirements')
            ->assertSee('Start your application first')
            ->assertSee('Start Application');
    }

    public function test_opening_my_application_resumes_the_current_draft_with_a_saved_cue(): void
    {
        $applicant = $this->applicant();
        ApplicantIntake::factory()->draft()->create([
            'user_id' => $applicant->id,
            'updated_at' => now()->subMinute(),
        ]);

        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)
            ->test(Application::class)
            ->assertSee('Continuing your saved draft')
            ->assertDontSee('Do you want to continue');
    }

    private function applicant(string $status = User::StatusApplicantPending): User
    {
        $applicant = User::factory()->create([
            'status' => $status,
            'email_verified_at' => now(),
        ]);
        $applicant->assignRole('applicant');

        return $applicant;
    }

    private function admissionsWindow(Term $term): CalendarEvent
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
            'authority' => 'TAL-96D5B applicant history test',
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
            'email' => 'synthetic-history-applicant@example.test',
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
}
