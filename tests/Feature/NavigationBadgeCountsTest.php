<?php

namespace Tests\Feature;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\StudentLifecycle\LifecycleFinanceAction;
use App\Filament\Applicant\Pages\Dashboard as ApplicantDashboard;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\Enrollments\Pages\ListEnrollments;
use App\Filament\Student\Pages\Dashboard as StudentDashboard;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Filament\Student\Pages\Finance as StudentFinancePage;
use App\Models\AdmissionApplication;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\IncCompletionSubmission;
use App\Models\PaymentEvidenceVersion;
use App\Models\RegistrationProposalConfirmation;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\TermAccount;
use App\Models\TranscriptRequest;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use App\Queries\Admissions\AdmissionQueueCounts;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NavigationBadgeCountsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['applicant', 'student', ...User::staffRoleNames()] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Role::findByName(User::StaffRoleRegistrar, 'web')->givePermissionTo([
            Permission::findOrCreate('approve-documents', 'web'),
            Permission::findOrCreate('manage-admission-setup', 'web'),
        ]);
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_accounting_sidebar_shows_rollup_and_action_queue_badges_only_when_positive(): void
    {
        $accounting = $this->staff(User::StaffRoleAccounting);
        $this->actingAs($accounting);
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleAccounting);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->resolveCurrentAdminPage(ListEnrollments::getRouteName());
        $baseline = $this->flattenNavigationItems($this->staffNavigation());
        $baselineUnique = (int) ($baseline['Student accounts']->getBadge() ?? 0);

        $this->assertNull($baseline['Overview']->getBadge());
        $this->assertNull($baseline['Fee plans']->getBadge());
        $this->assertNull($baseline['Payment channels']->getBadge());
        if ($baselineUnique === 0) {
            $this->assertNull($baseline['Student accounts']->getBadge());
        }

        $this->resolveCurrentAdminPage(ListEnrollments::getRouteName(), ['tab' => 'accounts']);
        $baselineChildren = $this->flattenNavigationItems($this->staffNavigation());
        $baselineAssessment = (int) ($baselineChildren['Assessment reviews']->getBadge() ?? 0);
        $baselineProof = (int) ($baselineChildren['Review receipts']->getBadge() ?? 0);
        $baselineChanges = (int) ($baselineChildren['Student status changes']->getBadge() ?? 0);
        $this->assertNull($baselineChildren['All student accounts']->getBadge());

        // Case 1: unassessed in-progress enrollment + submitted receipt (same enrollment -> deduplicated in rollup)
        $overlapCase = Enrollment::factory()->create([
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $overlapAccount = TermAccount::factory()->create(['enrollment_id' => $overlapCase->id]);
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $overlapAccount->id,
            'state' => PaymentEvidenceVersion::StateSubmitted,
            'checksum' => hash('sha256', 'nav-badge-overlap-proof'),
        ]);

        // Case 2: assessed official enrollment with pending student status change
        $changeCase = Enrollment::factory()->create([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
        ]);
        $changeAccount = TermAccount::factory()->create(['enrollment_id' => $changeCase->id]);
        Assessment::factory()->create([
            'enrollment_id' => $changeCase->id,
            'term_account_id' => $changeAccount->id,
            'state' => Assessment::StateActive,
            'version' => 1,
            'source_proposal_version_id' => $changeCase->current_proposal_version_id,
        ]);
        $change = StudentLifecycleChange::factory()->create([
            'enrollment_id' => $changeCase->id,
            'student_profile_id' => $changeCase->student_profile_id,
            'term_id' => $changeCase->term_id,
            'state' => StudentLifecycleChange::StateApplied,
        ]);
        app(LifecycleFinanceAction::class)->execute($change, $accounting);

        $this->resolveCurrentAdminPage(ListEnrollments::getRouteName());
        $overviewItems = $this->flattenNavigationItems($this->staffNavigation());
        $this->assertSame((string) ($baselineUnique + 2), $overviewItems['Student accounts']->getBadge());
        $this->assertSame('warning', $overviewItems['Student accounts']->getBadgeColor());
        $this->assertNotNull($overviewItems['Student accounts']->getBadgeTooltip());
        $this->assertNull($overviewItems['Overview']->getBadge());
        $this->assertNull($overviewItems['Fee plans']->getBadge());
        $this->assertNull($overviewItems['Payment channels']->getBadge());

        $this->resolveCurrentAdminPage(ListEnrollments::getRouteName(), ['tab' => 'accounts']);
        $expandedItems = $this->flattenNavigationItems($this->staffNavigation());
        $this->assertNull($expandedItems['All student accounts']->getBadge());
        $this->assertSame((string) ($baselineAssessment + 1), $expandedItems['Assessment reviews']->getBadge());
        $this->assertSame('warning', $expandedItems['Assessment reviews']->getBadgeColor());
        $this->assertSame((string) ($baselineProof + 1), $expandedItems['Review receipts']->getBadge());
        $this->assertSame('warning', $expandedItems['Review receipts']->getBadgeColor());
        $this->assertSame((string) ($baselineChanges + 1), $expandedItems['Student status changes']->getBadge());
        $this->assertSame('warning', $expandedItems['Student status changes']->getBadgeColor());
    }

    public function test_registrar_sidebar_updates_admissions_enrollment_and_grades_badges(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $this->actingAs($registrar);
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleRegistrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->resolveCurrentAdminPage(ListAdmissionApplications::getRouteName(), ['tab' => AdmissionQueueCounts::NeedsReview]);
        $baselineAdmissions = $this->flattenNavigationItems($this->staffNavigation());
        $baselineNeedsReview = (int) ($baselineAdmissions['Needs review']->getBadge() ?? 0);
        $baselineEnrollments = (int) ($baselineAdmissions['Students & Enrollment']->getBadge() ?? 0);
        $baselineGrades = (int) ($baselineAdmissions['Students Record']->getBadge() ?? 0);

        AdmissionApplication::factory()->submitted()->create();
        AdmissionApplication::factory()->create([
            'application_state' => AdmissionApplication::StateWithdrawn,
        ]);

        // Actionable in-progress enrollment (prepare subjects) vs non-actionable official enrollment
        Enrollment::factory()->create(['canonical_outcome' => Enrollment::OutcomeInProgress]);
        $officialEnrollment = Enrollment::factory()->create(['canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled]);

        // Submitted roster + submitted INC completion + open TOR request
        $roster = GradeRoster::factory()->create(['state' => GradeRoster::StateSubmitted]);
        $courseEnrollment = CourseEnrollment::factory()->create([
            'enrollment_id' => $officialEnrollment->id,
            'term_offering_id' => $roster->term_offering_id,
            'section_id' => $roster->section_id,
        ]);
        $row = GradeRosterRow::factory()->create([
            'grade_roster_id' => $roster->id,
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => 'INC',
            'released_at' => now(),
        ]);
        $outcomeEvent = GradeOutcomeEvent::factory()->create([
            'grade_roster_row_id' => $row->id,
            'result_code' => 'INC',
            'released_at' => now(),
        ]);
        IncCompletionSubmission::query()->create([
            'grade_outcome_event_id' => $outcomeEvent->id,
            'proposed_result' => '2.00',
            'completion_note' => 'Completed requirement.',
            'state' => IncCompletionSubmission::StateSubmitted,
            'controlling_result_event_id' => $outcomeEvent->id,
            'submitted_by' => $registrar->id,
            'submitted_at' => now(),
        ]);
        TranscriptRequest::factory()->create(['state' => TranscriptRequest::StateOpen]);

        $this->resolveCurrentAdminPage(ListAdmissionApplications::getRouteName(), ['tab' => AdmissionQueueCounts::NeedsReview]);
        $admissionsItems = $this->flattenNavigationItems($this->staffNavigation());
        $this->assertSame((string) ($baselineNeedsReview + 1), $admissionsItems['Needs review']->getBadge());
        $this->assertArrayNotHasKey('Closed applications', $admissionsItems);
        $this->assertArrayNotHasKey('Expired applications', $admissionsItems);
        $this->assertSame((string) ($baselineEnrollments + 1), $admissionsItems['Students & Enrollment']->getBadge());
        $this->assertSame('warning', $admissionsItems['Students & Enrollment']->getBadgeColor());
        $this->assertSame((string) ($baselineGrades + 3), $admissionsItems['Students Record']->getBadge());
        $this->assertSame('warning', $admissionsItems['Students Record']->getBadgeColor());

        $this->resolveCurrentAdminPage(GradesAndCompletion::getRouteName(), ['viewTab' => 'grade-review']);
        $gradesItems = $this->flattenNavigationItems($this->staffNavigation());
        $this->assertNotNull($gradesItems['Class grades']->getBadge());
        $this->assertNotNull($gradesItems['INC & Corrections']->getBadge());
        $this->assertNotNull($gradesItems['Completion & TOR']->getBadge());
        $this->assertNull($gradesItems['Student records']->getBadge());
        $this->assertNull($gradesItems['Lifecycle']->getBadge());
        $this->assertNull($gradesItems['Term Planning']->getBadge());
    }

    public function test_faculty_sidebar_badges_only_actionable_grade_rosters(): void
    {
        $faculty = $this->staff(User::StaffRoleFaculty);
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $returnedRoster = GradeRoster::factory()->create([
            'faculty_user_id' => $faculty->id,
            'state' => GradeRoster::StateReturned,
        ]);
        $releasedRoster = GradeRoster::factory()->create([
            'faculty_user_id' => $faculty->id,
            'state' => GradeRoster::StateReleased,
        ]);

        foreach ([$returnedRoster, $releasedRoster] as $roster) {
            ClassOfferingTeachingAssignment::query()->create([
                'term_offering_id' => $roster->term_offering_id,
                'section_id' => $roster->section_id,
                'faculty_user_id' => $faculty->id,
                'role' => ClassOfferingTeachingAssignment::RoleDesignated,
                'state' => ClassOfferingTeachingAssignment::StateActive,
                'assigned_by' => $registrar->id,
                'authority_reference' => 'NAV-BADGE-FACULTY-TEST',
                'effective_at' => now(),
            ]);
        }

        $this->actingAs($faculty);
        session()->put(WorkspaceContextResolver::SessionKey, User::StaffRoleFaculty);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->resolveCurrentAdminPage(GradesAndCompletion::getRouteName());

        $items = $this->flattenNavigationItems($this->staffNavigation());
        $this->assertSame('1', $items['Grade rosters']->getBadge());
        $this->assertSame('warning', $items['Grade rosters']->getBadgeColor());
        $this->assertNull($items['My schedule']->getBadge());
        $this->assertNull($items['My availability']->getBadge());
    }

    public function test_student_and_applicant_sidebars_badge_only_direct_learner_actions(): void
    {
        $student = User::factory()->create(['status' => User::StatusActive]);
        $student->assignRole('student');
        $profile = StudentProfile::factory()->create(['user_id' => $student->id]);
        $case = Enrollment::factory()->create([
            'student_profile_id' => $profile->id,
            'credential_user_id' => $student->id,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $case->id,
            'state' => RegistrationProposalVersion::StateIssued,
        ]);
        $case->update(['current_proposal_version_id' => $proposal->id]);

        $account = TermAccount::factory()->create([
            'enrollment_id' => $case->id,
            'credential_user_id' => $student->id,
            'term_id' => $case->term_id,
        ]);
        $assessment = Assessment::factory()->create([
            'enrollment_id' => $case->id,
            'term_account_id' => $account->id,
            'state' => Assessment::StateActive,
            'version' => 1,
        ]);
        AssessmentObligation::factory()->create([
            'assessment_id' => $assessment->id,
            'amount' => '5000.00',
            'due_at' => now()->subDay(),
            'required_for_enrollment' => true,
        ]);
        PaymentEvidenceVersion::factory()->create([
            'term_account_id' => $account->id,
            'state' => PaymentEvidenceVersion::StateRejected,
            'checksum' => hash('sha256', 'nav-badge-student-rejected'),
        ]);

        $this->actingAs($student);
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->resolveCurrentPanelPage('student', StudentEnrollmentPage::getRouteName(Filament::getPanel('student')));

        $studentEnrollmentNav = $this->flattenNavigationItems($this->studentNavigation());
        $this->assertNull($studentEnrollmentNav['Overview']->getBadge());
        $this->assertNull($studentEnrollmentNav['Academics']->getBadge());
        $this->assertSame('1', $studentEnrollmentNav['Enrollment']->getBadge());
        $this->assertSame('warning', $studentEnrollmentNav['Enrollment']->getBadgeColor());
        $this->assertSame('1', $studentEnrollmentNav['Current registration']->getBadge());
        $this->assertNull($studentEnrollmentNav['Certificate (COR)']->getBadge());
        $this->assertNull($studentEnrollmentNav['Class schedule']->getBadge());
        $this->assertSame('1', $studentEnrollmentNav['Fees and payments']->getBadge());
        $this->assertSame('warning', $studentEnrollmentNav['Fees and payments']->getBadgeColor());

        $this->resolveCurrentPanelPage('student', StudentFinancePage::getRouteName(Filament::getPanel('student')));
        $studentFinanceNav = $this->flattenNavigationItems($this->studentNavigation());
        $this->assertSame('1', $studentFinanceNav['This term']->getBadge());
        $this->assertNull($studentFinanceNav['Past statements']->getBadge());

        // Confirming the proposal clears the Enrollment badge once confirmed
        RegistrationProposalConfirmation::factory()->create([
            'registration_proposal_version_id' => $proposal->id,
            'learner_user_id' => $student->id,
        ]);
        $this->resolveCurrentPanelPage('student', StudentDashboard::getRouteName(Filament::getPanel('student')));
        $confirmedNav = $this->flattenNavigationItems($this->studentNavigation());
        $this->assertNull($confirmedNav['Enrollment']->getBadge());

        // Applicant panel: action_needed shows badge; submitted shows no badge
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $application = AdmissionApplication::factory()->create([
            'user_id' => $applicant->id,
            'application_state' => AdmissionApplication::StateActionNeeded,
        ]);

        $this->actingAs($applicant);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        $this->resolveCurrentPanelPage('applicant', ApplicantDashboard::getRouteName(Filament::getPanel('applicant')));

        $applicantNav = $this->flattenNavigationItems($this->applicantNavigation());
        $this->assertNull($applicantNav['Home']->getBadge());
        $this->assertSame('1', $applicantNav['Application']->getBadge());
        $this->assertSame('warning', $applicantNav['Application']->getBadgeColor());

        $application->update(['application_state' => AdmissionApplication::StateSubmitted]);
        $submittedApplicantNav = $this->flattenNavigationItems($this->applicantNavigation());
        $this->assertNull($submittedApplicantNav['Application']->getBadge());
    }

    /**
     * @param  array<NavigationGroup>  $groups
     * @return array<string, NavigationItem>
     */
    private function flattenNavigationItems(array $groups): array
    {
        $flat = [];
        $walk = function (iterable $items) use (&$flat, &$walk): void {
            foreach ($items as $item) {
                $flat[$item->getLabel()] = $item;
                $children = $item->getChildItems();
                if ($children !== [] && $children !== null) {
                    $walk($children);
                }
            }
        };

        foreach ($groups as $group) {
            $walk($group->getItems());
        }

        return $flat;
    }

    /**
     * @param  array<string, bool|int|string>  $query
     */
    private function resolveCurrentAdminPage(string $routeName, array $query = []): void
    {
        $this->resolveCurrentPanelPage('admin', $routeName, $query);
    }

    /**
     * @param  array<string, bool|int|string>  $query
     */
    private function resolveCurrentPanelPage(string $panelPath, string $routeName, array $query = []): void
    {
        $route = new Route(['GET'], '/'.$panelPath.'/test-page', fn (): null => null);
        $route->name($routeName);
        $request = Request::create('http://localhost/'.$panelPath.'/test-page', 'GET', $query);
        $route->bind($request);
        $request->setRouteResolver(fn (): Route => $route);
        $this->app->instance('originalRequest', $request);
    }

    /**
     * @return array<NavigationGroup>
     */
    private function staffNavigation(): array
    {
        $method = new \ReflectionMethod(AdminPanelProvider::class, 'staffNavigation');

        return $method->invoke(new AdminPanelProvider($this->app), new NavigationBuilder)->getNavigation();
    }

    /**
     * @return array<NavigationGroup>
     */
    private function studentNavigation(): array
    {
        $this->app->forgetInstance(NavigationManager::class);

        return Filament::getPanel('student')->getNavigation();
    }

    /**
     * @return array<NavigationGroup>
     */
    private function applicantNavigation(): array
    {
        $this->app->forgetInstance(NavigationManager::class);

        return Filament::getPanel('applicant')->getNavigation();
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $user->saveAppAuthenticationRecoveryCodes(['stored-code']);

        return $user;
    }
}
