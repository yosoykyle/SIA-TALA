<?php

namespace Tests\Feature;

use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Actions\Applicants\AdmissionWindowService;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementSet;
use App\Models\Enrollment;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TAL96D5BAdmissionsWindowAndWithdrawalTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(
            Permission::findOrCreate('approve-documents', 'web'),
        );
    }

    public function test_published_admission_cycle_is_the_single_public_and_submission_window_source(): void
    {
        [$cycle] = $this->openCycle();
        $service = app(AdmissionWindowService::class);

        $this->assertTrue($service->hasOpenAdmissionsWindow());
        $this->assertTrue($service->isAdmissionsWindowOpenForTerm($cycle->term_id));
        $this->assertTrue($service->admissionsCycle($cycle->term_id)->is($cycle));

        $cycle->update(['closes_at' => now()->subSecond()]);

        $this->assertFalse($service->hasOpenAdmissionsWindow());
    }

    public function test_partial_draft_is_preserved_when_the_cycle_closes(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $action = app(SaveAdmissionApplication::class);
        $draft = $action->execute($applicant, $cycle, [
            'application_path' => AdmissionApplication::PathFirstYear,
            'program_id' => $program->id,
            'first_name' => 'Safe',
        ]);
        $cycle->update(['closes_at' => now()->subSecond()]);

        try {
            $action->execute($applicant, $cycle, ['last_name' => 'Draft'], $draft);
            $this->fail('A closed cycle must reject further Draft changes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('admission_cycle_id', $exception->errors());
        }

        $this->assertSame('Safe', $draft->fresh()->first_name);
        $this->assertNull($draft->fresh()->last_name);
    }

    public function test_close_between_page_load_and_first_submission_preserves_the_draft_without_a_snapshot(): void
    {
        [$cycle, $program] = $this->openCycle();
        $applicant = $this->applicant();
        $set = AdmissionRequirementSet::factory()->published()->create([
            'admission_cycle_id' => $cycle->id,
            'application_path' => AdmissionApplication::PathFirstYear,
        ]);
        $draft = app(SaveAdmissionApplication::class)->execute($applicant, $cycle, [
            'program_id' => $program->id,
            'application_path' => AdmissionApplication::PathFirstYear,
            'credential_basis' => AdmissionApplication::CredentialSeniorHighSchool,
            'first_name' => 'Close',
            'last_name' => 'Race',
            'birth_date' => '2005-01-02',
            'citizenship_country_code' => 'PH',
            'phone' => '09171234567',
            'current_city_municipality' => 'Synthetic City',
            'current_province' => 'Laguna',
            'prior_school_name' => 'Synthetic Senior High School',
            'prior_school_country_code' => 'PH',
            'prior_school_completion_year' => 2025,
            'privacy_acknowledged' => true,
            'accuracy_declared' => true,
        ]);
        $this->assertSame(AdmissionRequirementSet::StatePublished, $set->state);
        $cycle->update(['closes_at' => now()->subSecond()]);

        try {
            app(SubmitAdmissionApplication::class)->execute($draft, $applicant);
            $this->fail('A stale first submission must be rejected after closing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('admission_cycle_id', $exception->errors());
        }

        $this->assertSame(AdmissionApplication::StateDraft, $draft->fresh()->application_state);
        $this->assertSame(0, $draft->submissionVersions()->count());
    }

    public function test_withdrawal_and_authorized_reopening_preserve_the_same_reference_and_history(): void
    {
        $application = AdmissionApplication::factory()->submitted()->create();
        $application->user->forceFill(['status' => User::StatusActive])->save();
        $application->user->assignRole('applicant');
        $application->admissionCycle->forceFill([
            'state' => AdmissionCycle::StatePublished,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ])->save();
        $registrar = $this->registrar();
        $reference = $application->application_reference;
        $lifecycle = app(ChangeAdmissionApplicationLifecycle::class);

        $withdrawn = $lifecycle->withdrawByApplicant($application, $application->user, 'Plans changed.');
        $reopened = $lifecycle->reopen(
            $withdrawn,
            $registrar,
            'Applicant requested an authorized reopening.',
            'Synthetic Registrar authority',
        );

        $this->assertSame($reference, $reopened->application_reference);
        $this->assertSame(AdmissionApplication::StateSubmitted, $reopened->application_state);
        $this->assertGreaterThanOrEqual(2, $reopened->events()->count());
    }

    public function test_withdrawal_stops_after_clinic_four_registration_has_started(): void
    {
        $application = AdmissionApplication::factory()->submitted()->create();
        $application->user->forceFill(['status' => User::StatusActive])->save();
        $application->user->assignRole('applicant');
        $student = StudentProfile::factory()->create([
            'applicant_intake_id' => $application->id,
            'program_id' => $application->program_id,
        ]);
        Enrollment::factory()->for($student)->create(['term_id' => $application->term_id]);

        $this->expectException(ValidationException::class);
        app(ChangeAdmissionApplicationLifecycle::class)
            ->withdrawByApplicant($application, $application->user, null);
    }

    /** @return array{AdmissionCycle, Program} */
    private function openCycle(): array
    {
        $term = Term::factory()->create(['state' => Term::StateActive]);
        $program = Program::factory()->create(['is_active' => true]);
        $cycle = AdmissionCycle::factory()->for($term)->published()->create([
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDay(),
        ]);
        $cycle->programs()->attach($program->id, [
            'accepts_first_year' => true,
            'accepts_transferee' => true,
        ]);

        return [$cycle, $program];
    }

    private function applicant(): User
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');

        return $applicant;
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
