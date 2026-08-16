<?php

namespace Tests\Feature;

use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicantIntake;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApplicantIntakeSubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
    }

    public function test_canonical_application_accepts_a_partial_draft_without_inventing_missing_facts(): void
    {
        [$cycle] = $this->openCycle();
        $applicant = $this->applicant();

        $draft = app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            ['first_name' => 'Partial'],
        );

        $this->assertSame(AdmissionApplication::StateDraft, $draft->application_state);
        $this->assertNull($draft->program_id);
        $this->assertNull($draft->application_path);
        $this->assertStringStartsWith('APP-', $draft->application_reference);

        $this->expectException(ValidationException::class);
        app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
    }

    public function test_one_account_can_own_only_one_application_in_the_same_cycle(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $action = app(SaveAdmissionApplication::class);

        $first = $action->execute($applicant, $cycle, $this->completeData($program));
        $same = $action->execute($applicant, $cycle, ['current_province' => 'Cavite']);

        $this->assertTrue($first->is($same));
        $this->assertSame('Cavite', $same->current_province);
        $this->assertSame(1, AdmissionApplication::query()
            ->where('user_id', $applicant->id)
            ->where('admission_cycle_id', $cycle->id)
            ->count());
    }

    public function test_submission_creates_an_immutable_version_without_student_handover(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $draft = app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            $this->completeData($program),
        );

        $submitted = app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
        $version = $submitted->currentSubmissionVersion;

        $this->assertSame(AdmissionApplication::StateSubmitted, $submitted->application_state);
        $this->assertSame($submitted->application_reference, $version->snapshot['application_reference']);
        $this->assertSame(1, $submitted->submissionVersions()->count());
        $this->assertSame(0, StudentProfile::query()->where('user_id', $applicant->id)->count());

        $this->expectException(ValidationException::class);
        app(SubmitAdmissionApplication::class)->execute($submitted, $applicant);
    }

    private function applicant(): User
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');

        return $applicant;
    }

    /** @return array{AdmissionCycle, Program} */
    private function openCycle(): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $cycle = AdmissionCycle::factory()->published()->create([
            'term_id' => $term->id,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);
        $cycle->programs()->attach($program, [
            'accepts_first_year' => true,
            'accepts_transferee' => false,
        ]);
        $set = AdmissionRequirementSet::factory()->for($cycle)->create([
            'application_path' => AdmissionCycle::PathFirstYear,
        ]);
        AdmissionRequirement::factory()->for($set, 'requirementSet')->create([
            'requires_preliminary_evidence' => false,
        ]);
        $set->update([
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now()->subMinute(),
            'published_at' => now()->subMinute(),
        ]);

        return [$cycle, $program];
    }

    /** @return array<string, mixed> */
    private function completeData(Program $program): array
    {
        return [
            'program_id' => $program->id,
            'application_path' => AdmissionApplication::PathFirstYear,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'first_name' => 'Alma',
            'last_name' => 'Applicant',
            'birth_date' => now()->subYears(20)->toDateString(),
            'citizenship_country_code' => 'PH',
            'phone' => '09477379208',
            'current_city_municipality' => 'Calamba',
            'current_province' => 'Laguna',
            'prior_school_name' => 'Synthetic Senior High School',
            'prior_school_country_code' => 'PH',
            'prior_school_completion_year' => now()->year - 1,
            'privacy_acknowledged' => true,
            'accuracy_declared' => true,
        ];
    }
}
