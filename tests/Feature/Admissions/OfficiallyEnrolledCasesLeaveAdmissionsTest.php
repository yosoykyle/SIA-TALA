<?php

namespace Tests\Feature\Admissions;

use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Enrollment;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\AdmissionQueueCounts;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use App\Support\NavigationBadgeCounts;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * #99 Scope 8: an officially enrolled case leaves the Admissions queues and counts.
 */
class OfficiallyEnrolledCasesLeaveAdmissionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
    }

    public function test_officially_enrolled_case_leaves_ready_for_enrollment_without_moving_to_another_queue(): void
    {
        $before = $this->queueTotals();
        $noEnrollment = $this->clearedApplication();
        $inProgress = $this->clearedApplication();
        $enrolled = $this->clearedApplication(AdmissionApplication::PathTransferee);
        $this->enrollment($inProgress, Enrollment::OutcomeInProgress, 'pending_payment');
        $this->enrollment($enrolled, Enrollment::OutcomeOfficiallyEnrolled, 'officially_enrolled');

        $readyIds = app(ReadyApplicantProjectionQuery::class)->readyApplicationIds();
        $this->assertContains($noEnrollment->id, $readyIds);
        $this->assertContains($inProgress->id, $readyIds);
        $this->assertNotContains($enrolled->id, $readyIds);

        $queues = app(AdmissionQueueCounts::class);
        foreach (AdmissionQueueCounts::labels() as $queue => $label) {
            $this->assertNotContains($enrolled->id, $queues->scope(AdmissionApplication::query(), $queue)->pluck('id'), "Officially enrolled case is listed in {$label}.");
        }

        $after = $this->queueTotals();
        $this->assertSame($before['ready'] + 2, $after['ready']);
        $this->assertSame($before['clearance'], $after['clearance']);
        $this->assertSame($before['closed'], $after['closed']);
        $this->assertSame($before['active'] + 2, $after['active']);
        $this->assertSame($before['transfereeActive'], $after['transfereeActive']);
        $this->assertSame($before['badgeReady'] + 2, $after['badgeReady']);
        $this->assertSame($before['badgeClearance'], $after['badgeClearance']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->registrar())
            ->test(ListAdmissionApplications::class, ['activeTab' => AdmissionQueueCounts::ReadyForEnrollment])
            ->assertCanSeeTableRecords([$noEnrollment, $inProgress])
            ->assertCanNotSeeTableRecords([$enrolled]);
    }

    /** @return array{ready: int, clearance: int, closed: int, active: int, transfereeActive: int, badgeReady: int, badgeClearance: int} */
    private function queueTotals(): array
    {
        $queues = app(AdmissionQueueCounts::class);
        $badges = (new NavigationBadgeCounts)->admissions();

        return [
            'ready' => $queues->count(AdmissionQueueCounts::ReadyForEnrollment),
            'clearance' => $queues->count(AdmissionQueueCounts::RegistrarClearance),
            'closed' => $queues->count(AdmissionQueueCounts::Closed),
            'active' => $queues->activeCases()['total'],
            'transfereeActive' => $queues->activeCases(AdmissionApplication::PathTransferee)['total'],
            'badgeReady' => $badges[AdmissionQueueCounts::ReadyForEnrollment],
            'badgeClearance' => $badges[AdmissionQueueCounts::RegistrarClearance],
        ];
    }

    private function clearedApplication(string $path = AdmissionApplication::PathFirstYear): AdmissionApplication
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $cycle = AdmissionCycle::factory()->published()->create([
            'term_id' => Term::query()->value('id') ?? Term::factory()->create()->id,
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addMonth(),
            'correction_closes_at' => now()->addMonth(),
        ]);
        $application = AdmissionApplication::factory()->submitted()->create([
            'user_id' => $applicant->id,
            'admission_cycle_id' => $cycle->id,
            'application_path' => $path,
        ]);
        $set = AdmissionRequirementSet::factory()->create(['admission_cycle_id' => $cycle->id, 'application_path' => $path]);
        AdmissionRequirement::factory()->create(['admission_requirement_set_id' => $set->id, 'due_stage' => AdmissionRequirement::DueEnrollmentReadiness]);
        $set->update(['state' => AdmissionRequirementSet::StatePublished, 'published_at' => now(), 'effective_at' => now()]);
        $version = ApplicationSubmissionVersion::factory()->create(['admission_application_id' => $application->id, 'admission_requirement_set_id' => $set->id, 'submitted_by' => $applicant->id]);
        AdmissionDecision::factory()->create([
            'admission_application_id' => $application->id,
            'application_submission_version_id' => $version->id,
            'decision' => AdmissionDecision::DecisionAdmitted,
        ]);
        $application->update(['current_submission_version_id' => $version->id, 'application_state' => AdmissionApplication::StateAdmitted]);
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($application->fresh())->create([
            'result' => RegistrarEnrollmentClearance::ResultCleared,
            'external_checks_confirmed' => true,
        ]);

        $this->assertTrue(app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready']);

        return $application->fresh();
    }

    private function enrollment(AdmissionApplication $application, string $outcome, string $status): void
    {
        Enrollment::factory()->create([
            'student_profile_id' => StudentProfile::factory()->create(['user_id' => $application->user_id])->id,
            'credential_user_id' => $application->user_id,
            'admission_application_id' => $application->id,
            'term_id' => $application->term_id,
            'canonical_outcome' => $outcome,
            'status' => $status,
        ]);
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
