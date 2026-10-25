<?php

namespace Tests\Feature;

use App\Actions\Admissions\AdmissionReviewGuard;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Filament\Applicant\Pages\Application;
use App\Filament\Applicant\Pages\ApplicationHistory;
use App\Filament\Applicant\Pages\Dashboard;
use App\Filament\Applicant\Pages\SubmissionReceipt;
use App\Filament\Pages\AssistedAdmissionApplication;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationSubmissionVersion;
use App\Models\Program;
use App\Models\PublicNotice;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\AdmissionQueueCounts;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Issue61ApplicantHomeDashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('applicant', 'web');
        Role::findOrCreate(User::StaffRoleRegistrar, 'web')->givePermissionTo(Permission::findOrCreate('approve-documents', 'web'));
    }

    public function test_draft_home_leads_with_the_journey_and_one_continue_action(): void
    {
        [$application, $applicant] = $this->application(submitted: false);

        $this->home($applicant, $application)
            ->assertSee('Your application journey')
            ->assertSee('Step 1 of 5 · Application')
            ->assertSee('Continue application')
            ->assertSee('Application status')
            ->assertSee('Not yet submitted. Finish and submit your application before applications close.')
            ->assertSee('Important dates')
            ->assertSee('Applications open')
            ->assertSee('Applications close')
            ->assertSee('aria-current="step"', false)
            ->assertDontSee('Responsible party')
            ->assertDontSee('Preliminary copies')
            ->assertDontSee('Your application draft');
    }

    public function test_home_offers_no_destructive_draft_action(): void
    {
        [$application, $applicant] = $this->application(submitted: false);

        $this->home($applicant, $application)
            ->assertActionDoesNotExist('discardDraft')
            ->assertDontSee('Discard draft');
    }

    public function test_journey_stages_follow_existing_application_facts(): void
    {
        [$draft, $draftOwner] = $this->application(submitted: false);
        $this->assertStages($draftOwner, $draft, ['current', 'pending', 'pending', 'pending', 'pending'], 1);

        [$checking, $checkingOwner] = $this->application(preliminaryEvidence: true);
        $this->assertStages($checkingOwner, $checking, ['completed', 'current', 'pending', 'pending', 'pending'], 2);

        [$review, $reviewOwner] = $this->application();
        $this->assertStages($reviewOwner, $review, ['completed', 'completed', 'current', 'pending', 'pending'], 3);

        [$admitted, $admittedOwner] = $this->application();
        $this->decide($admitted, AdmissionDecision::DecisionAdmitted);
        $this->assertStages($admittedOwner, $admitted->fresh(), ['completed', 'completed', 'completed', 'completed', 'current'], 5);

        [$declined, $declinedOwner] = $this->application();
        $this->decide($declined, AdmissionDecision::DecisionNotAdmitted);
        $this->assertStages($declinedOwner, $declined->fresh(), ['completed', 'completed', 'completed', 'completed', 'unavailable'], 4);
    }

    public function test_submitted_home_offers_one_update_action_until_review_starts(): void
    {
        [$application, $applicant] = $this->application();

        $this->home($applicant, $application)
            ->assertSee('Step 3 of 5 · Registrar review')
            ->assertSee('Awaiting Registrar review. You can still update your application until the Registrar starts reviewing it.')
            ->assertSee('Waiting for review')
            ->assertSee('Update application')
            ->assertDontSee('Continue application')
            ->assertDontSee('Responsible party');
    }

    public function test_waiting_home_names_the_office_once_review_has_started(): void
    {
        [$application, $applicant] = $this->application();
        $application->forceFill(['reviewed_by' => $this->registrar()->id, 'reviewed_at' => now()])->saveQuietly();

        $this->home($applicant, $application->fresh())
            ->assertSee('The Registrar is reviewing your application.')
            ->assertSee('Registrar is reviewing')
            ->assertDontSee('Update application')
            ->assertDontSee('Continue application');
    }

    public function test_correction_puts_its_due_date_in_important_dates_and_one_respond_action(): void
    {
        [$application, $applicant] = $this->application();
        $registrar = $this->registrar();
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        app(RequestAdmissionCorrection::class)->execute($application->fresh(), $registrar, [[
            'type' => ApplicationCorrectionItem::ScopeField, 'key' => 'phone', 'admission_requirement_id' => null,
        ]], 'Correct the phone.', 'Applicant', now()->addHour());

        $this->home($applicant, $application->fresh())
            ->assertSee('The Registrar asked you to correct part of your application.')
            ->assertSee('Correction due')
            ->assertSee('Respond to correction')
            ->assertSee('Action Needed');
    }

    public function test_important_dates_use_only_existing_cycle_and_correction_facts(): void
    {
        [$application, $applicant] = $this->application();
        $dates = collect($this->home($applicant, $application)->instance()->importantDates($application->fresh()))->pluck('label')->all();

        $this->assertSame(['Applications open', 'Applications close', 'Decision'], $dates);
    }

    public function test_home_without_an_application_starts_at_the_first_stage(): void
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        $page = Livewire::actingAs($applicant)->test(Dashboard::class);

        $this->assertSame(['current', 'pending', 'pending', 'pending', 'pending'], array_column($page->instance()->journeyStages(null), 'status'));
        $page->assertSee('Step 1 of 5 · Application')
            ->assertSeeInOrder(['Application', 'Not started', 'Requirements'])
            ->assertDontSee('In progress')
            ->assertSee("You haven't started an application yet.", false)
            ->assertSee('Your required documents appear here once you start an application.');
    }

    public function test_home_greets_the_applicant_before_summary_cards_and_journey(): void
    {
        [$application, $applicant] = $this->application(submitted: false);
        $applicant->forceFill(['first_name' => 'Ana'])->saveQuietly();

        $this->home($applicant->fresh(), $application)
            ->assertSeeInOrder(['Hi, Ana!', "Here's what's happening with your application.", 'Application status', 'Application deadline', 'Applications close', 'Your application journey']);

        $nameless = User::factory()->create(['status' => User::StatusActive]);
        $nameless->forceFill(['first_name' => null])->saveQuietly();
        $nameless->assignRole('applicant');
        Filament::setCurrentPanel(Filament::getPanel('applicant'));
        Livewire::actingAs($nameless->fresh())->test(Dashboard::class)->assertSee('Hi there!');
    }

    public function test_history_moves_from_home_to_its_own_page_reached_from_the_account_menu(): void
    {
        [$application, $applicant] = $this->application();

        $this->home($applicant, $application)
            ->assertDontSee('Application details and history')
            ->assertDontSee('View receipt');

        $this->actingAs($applicant)
            ->get(ApplicationHistory::getUrl(panel: 'applicant'))
            ->assertOk()
            ->assertSee("Applicant's History")
            ->assertSee('Application details and history')
            ->assertSee('View receipt')
            ->assertSee(SubmissionReceipt::getUrl(['application' => $application->id, 'version' => $application->current_submission_version_id], panel: 'applicant'));

        Livewire::test(ApplicationHistory::class)->searchTable('NO_MATCHING_REFERENCE')
            ->assertSee('No matching applications')->assertSee('Try another reference or clear the search.')
            ->assertDontSee('No application yet')
            ->searchTable('')->assertSee($application->application_reference);

        $this->actingAs($applicant)
            ->get(Dashboard::getUrl(panel: 'applicant'))
            ->assertSeeInOrder(['Account security', "Applicant's History", 'Sign out']);
    }

    public function test_registrar_sidebar_shows_admissions_queues_with_counts(): void
    {
        $this->application();
        $this->application();
        $registrar = $this->registrar();
        $registrar->saveAppAuthenticationSecret('base32secret3232');
        $registrar->acknowledgeRecoveryCodeStorage();
        $needsReview = app(AdmissionQueueCounts::class)->count(AdmissionQueueCounts::NeedsReview);

        $queueList = $this->actingAs($registrar)
            ->get(ListAdmissionApplications::getUrl(['tab' => AdmissionQueueCounts::NeedsReview], panel: 'admin'))
            ->assertOk()
            ->assertSeeInOrder(['Admissions', 'Needs review', 'Waiting for applicant', 'Registrar clearance', 'Ready for enrollment', 'Closed applications', 'Students &amp; Enrollment'], false)
            ->assertSee(ListAdmissionApplications::getUrl(['tab' => AdmissionQueueCounts::RegistrarClearance], panel: 'admin'));
        $this->assertGreaterThanOrEqual(2, $needsReview);
        $this->assertStringContainsString('>'.$needsReview.'<', preg_replace('/\s+/', '', (string) $queueList->getContent()));

        $this->assertStringContainsString('fi-sidebar-sub-group-items', (string) $queueList->getContent());
    }

    public function test_waiting_and_decided_states_offer_no_primary_action(): void
    {
        $registrar = $this->registrar();
        [$underReview, $reviewOwner] = $this->application();
        app(AdmissionReviewGuard::class)->start($underReview, $registrar, $underReview->current_submission_version_id);
        [$admitted, $admittedOwner] = $this->application();
        $this->decide($admitted, AdmissionDecision::DecisionAdmitted);
        RegistrarEnrollmentClearance::factory()->forAdmittedApplication($admitted->fresh())->create(['result' => RegistrarEnrollmentClearance::ResultCleared, 'external_checks_confirmed' => true, 'recorded_by' => $registrar->id]);
        [$declined, $declinedOwner] = $this->application();
        $this->decide($declined, AdmissionDecision::DecisionNotAdmitted);
        [$withdrawn, $withdrawnOwner] = $this->application();
        $withdrawn->update(['application_state' => AdmissionApplication::StateWithdrawn]);

        foreach ([[$underReview, $reviewOwner], [$admitted, $admittedOwner], [$declined, $declinedOwner], [$withdrawn, $withdrawnOwner]] as [$application, $owner]) {
            $this->home($owner, $application->fresh())
                ->assertSee('Your application journey')
                ->assertDontSee('Continue application')
                ->assertDontSee('Update application')
                ->assertDontSee('Respond to correction')
                ->assertDontSee('Review requirements');
        }
    }

    public function test_closed_cycle_draft_offers_a_quiet_inspect_link_instead_of_a_primary_action(): void
    {
        [$application, $applicant] = $this->application(submitted: false);
        $application->admissionCycle->update(['opens_at' => now()->subDays(3), 'closes_at' => now()->subDay()]);

        $this->home($applicant, $application->fresh())
            ->assertSeeHtml('<a class="tala-home-link" href="'.e(Application::getUrl(['application' => $application->id], panel: 'applicant')).'">Inspect saved draft</a>')
            ->assertDontSee('Continue application');
    }

    public function test_overdue_correction_still_offers_one_respond_action(): void
    {
        [$application, $applicant] = $this->application();
        $registrar = $this->registrar();
        app(AdmissionReviewGuard::class)->start($application, $registrar, $application->current_submission_version_id);
        app(RequestAdmissionCorrection::class)->execute($application->fresh(), $registrar, [[
            'type' => ApplicationCorrectionItem::ScopeField, 'key' => 'phone', 'admission_requirement_id' => null,
        ]], 'Correct the phone.', 'Applicant', now()->addHour());
        $application->correctionRequests()->update(['due_at' => now()->subHour()]);

        $this->home($applicant, $application->fresh())
            ->assertSee('Correction overdue')
            ->assertSee('Respond to correction');
    }

    public function test_submitted_application_shows_the_open_cycle_closing_time(): void
    {
        [$application, $applicant] = $this->application();

        $this->home($applicant, $application)
            ->assertSee('Applications close')
            ->assertSee('You can still update your application until review starts (Asia/Manila).')
            ->assertDontSee('Nothing is due from you right now.');
    }

    public function test_sex_and_civil_status_accept_only_fixed_choices_or_the_recorded_value(): void
    {
        [$application, $applicant] = $this->application(submitted: false);
        $application->admissionCycle->programs()->syncWithoutDetaching([$application->program_id => ['accepts_first_year' => true, 'accepts_transferee' => true]]);
        $save = fn (array $data) => app(SaveAdmissionApplication::class)->execute($applicant, $application->admissionCycle, $data + ['optional_identity_consent' => true], $application->fresh());

        try {
            $save(['gender' => 'Other']);
            $this->fail('A free-text Sex value outside the fixed choices was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('gender', $exception->errors());
        }

        $application->forceFill(['optional_identity_consented_at' => now(), 'civil_status' => 'Solo'])->saveQuietly();
        $save(['civil_status' => 'Solo', 'gender' => 'Female']);
        $this->assertSame('Solo', $application->fresh()->civil_status);
        $this->assertSame('Female', $application->fresh()->gender);
    }

    public function test_withdrawn_and_admitted_clearance_pending_applications_show_their_journey_stages(): void
    {
        [$withdrawn, $withdrawnOwner] = $this->application();
        $withdrawn->update(['application_state' => AdmissionApplication::StateWithdrawn]);
        $this->assertStages($withdrawnOwner, $withdrawn->fresh(), ['completed', 'completed', 'unavailable', 'unavailable', 'unavailable'], 2);
        $this->home($withdrawnOwner, $withdrawn->fresh())->assertSee('Stopped');

        [$admitted, $admittedOwner] = $this->application();
        $this->decide($admitted, AdmissionDecision::DecisionAdmitted);
        $stages = app(Dashboard::class)->journeyStages($admitted->fresh());
        $this->assertSame(['completed', 'completed', 'completed', 'completed', 'current'], array_column($stages, 'status'));
        $this->assertSame('Pending', $stages[4]['detail']);
        $this->home($admittedOwner, $admitted->fresh())->assertSee('Step 5 of 5');
    }

    public function test_registrar_assisted_entry_lists_full_program_names_while_applicants_see_short_ones(): void
    {
        [$application] = $this->application(submitted: false);
        $application->program->update(['name' => 'Diploma in Information Technology']);
        $application->admissionCycle->programs()->syncWithoutDetaching([$application->program_id => ['accepts_first_year' => true, 'accepts_transferee' => true]]);
        $options = fn (string $page): array => (new \ReflectionMethod($page, 'programOptions'))->invoke(new $page, $application->admission_cycle_id, $application->application_path);

        $this->assertSame('Information Technology', $options(Application::class)[$application->program_id]);
        $this->assertSame('Diploma in Information Technology', $options(AssistedAdmissionApplication::class)[$application->program_id]);
    }

    public function test_landing_shows_the_about_section_between_the_hero_and_programs(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="#about"', false)
            ->assertSeeInOrder(['id="top"', 'About Servitech Institute Asia', 'Objectives', 'Be the leading TechVoc Training and Assessment Center in Region IV-A.', 'Plan your visit', 'Established', '2017', 'San Pedro, Laguna', 'TESDA', 'id="programs"'], false);
    }

    public function test_registrar_assisted_entry_keeps_full_program_names(): void
    {
        $this->assertTrue((new \ReflectionMethod(Application::class, 'showsApplicantProgramNames'))->invoke(new Application));
        $this->assertFalse((new \ReflectionMethod(AssistedAdmissionApplication::class, 'showsApplicantProgramNames'))->invoke(new AssistedAdmissionApplication));
    }

    public function test_student_dashboard_shows_readable_status_labels(): void
    {
        Role::findOrCreate('student', 'web');
        $student = User::factory()->create(['status' => User::StatusActive, 'email_verified_at' => now()]);
        $student->assignRole('student');
        StudentProfile::factory()->create(['user_id' => $student->id, 'lifecycle_status' => StudentProfile::LifecycleActive]);

        $this->actingAs($student)->get('/student')
            ->assertOk()
            ->assertSee('Active')
            ->assertDontSee('A C T I V E');
    }

    public function test_review_step_groups_answers_into_category_cards(): void
    {
        [$application, $applicant] = $this->application(submitted: false);
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->assertSeeHtml('tala-review-card')
            ->assertSeeInOrder(['Application choice', 'Personal information', 'Contact and address', 'Prior education', 'Parent, guardian or emergency contact', 'Optional identity details', 'Review copies', 'Declaration'])
            ->assertSeeHtml('<span class="tala-review-term">Program</span>')
            ->assertSeeHtml('<span class="tala-review-term">Student type</span> Freshman')
            ->assertSeeHtml('<span class="tala-review-term">LRN</span>');
    }

    public function test_applicants_see_program_names_without_the_diploma_prefix(): void
    {
        [$application, $applicant] = $this->application(submitted: false);
        $application->program->update(['name' => 'Diploma in Information Technology']);

        $this->home($applicant, $application->fresh())
            ->assertSee('Information Technology')
            ->assertDontSee('Diploma in Information Technology');

        $this->assertSame('Tourism and Hospitality Management Services', Program::applicantName('Diploma in Tourism and Hospitality Management Services'));
        $this->assertSame('Bachelor of Science in Nursing', Program::applicantName('Bachelor of Science in Nursing'));
        $this->assertSame('Diploma in', Program::applicantName('Diploma in'));
    }

    public function test_requirements_checklist_follows_the_application_requirement_set(): void
    {
        [$draft, $draftOwner] = $this->application(submitted: false);
        $this->home($draftOwner, $draft)
            ->assertSee('Requirements checklist')
            ->assertSeeInOrder(['School review copy', 'Not uploaded'])
            ->assertSee('Upload in your application');

        [$submitted, $submittedOwner] = $this->application();
        $this->home($submittedOwner, $submitted)
            ->assertSeeInOrder(['School review copy', 'Not submitted'])
            ->assertSee('View requirements');
    }

    public function test_applicant_pages_show_a_date_and_time_top_bar_without_account_controls(): void
    {
        [$application, $applicant] = $this->application(submitted: false);
        $applicant->forceFill(['name' => 'Ana Reyes'])->saveQuietly();

        $this->actingAs($applicant)
            ->get(Dashboard::getUrl(['application' => $application->id], panel: 'applicant'))
            ->assertOk()
            ->assertSee('tala-applicant-topbar', false)
            ->assertSeeInOrder(['tala-applicant-topbar__person', 'Ana Reyes', 'tala-applicant-topbar__end', 'data-tala-clock'], false)
            ->assertDontSee('tala-brand__person', false)
            ->assertSee(now()->timezone('Asia/Manila')->format('D, M j, Y'))
            ->assertSee('Asia/Manila')
            ->assertDontSee('fi-topbar-ctn', false);
    }

    public function test_wizard_offers_psa_style_sex_and_civil_status_choices_and_keeps_recorded_text(): void
    {
        [$application, $applicant] = $this->application(submitted: false);
        $application->forceFill(['optional_identity_consented_at' => now(), 'gender' => 'Lalaki', 'civil_status' => 'Single'])->saveQuietly();
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        Livewire::actingAs($applicant)->test(Application::class, ['sourceApplicationId' => $application->id])
            ->assertFormFieldExists('gender', fn (Select $field): bool => array_keys($field->getOptions()) === ['Male', 'Female', 'Lalaki'])
            ->assertFormFieldExists('civil_status', fn (Select $field): bool => array_keys($field->getOptions()) === ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'])
            ->assertFormFieldExists('lrn', fn (TextInput $field): bool => $field->isRequired())
            ->assertDontSee('Not issued');
    }

    public function test_staff_login_has_no_applicant_top_bar(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSee('tala-applicant-topbar', false);
    }

    public function test_need_help_shows_only_a_published_support_contact(): void
    {
        [$application, $applicant] = $this->application();
        $application->admissionCycle->forceFill(['support_contact' => 'admissions@servitech.example'])->saveQuietly();

        $this->home($applicant, $application->fresh())
            ->assertSee('Need help?')
            ->assertSee('mailto:admissions@servitech.example', false)
            ->assertSee('Contact support')
            ->assertSee(e(route('home', ['modal' => 'support'])), false);

        $application->admissionCycle->forceFill(['support_contact' => ''])->saveQuietly();
        $this->home($applicant, $application->fresh())->assertDontSee('Need help?');
    }

    public function test_important_dates_show_effective_announcements_read_only(): void
    {
        [$application, $applicant] = $this->application();
        $this->home($applicant, $application)->assertSee('No announcements yet.');

        $notice = PublicNotice::factory()->create(['title' => 'Orientation week schedule']);
        $notice->forceFill(['state' => 'Published', 'published_at' => now(), 'ever_published' => true, 'version' => 1])->save();

        $this->home($applicant, $application)
            ->assertSee('Orientation week schedule')
            ->assertDontSee('No announcements yet.');
    }

    /** @param list<string> $expected */
    private function assertStages(User $applicant, AdmissionApplication $application, array $expected, int $step): void
    {
        $page = $this->home($applicant, $application)->instance();
        $stages = $page->journeyStages($application);

        $this->assertSame(['Application', 'Requirements', 'Registrar review', 'Admission decision', 'Enrollment clearance'], array_column($stages, 'label'));
        $this->assertSame($expected, array_column($stages, 'status'), $application->application_state);
        $this->assertSame($step, $page->journeyStepNumber($stages));
    }

    private function home(User $applicant, AdmissionApplication $application): Testable
    {
        Filament::setCurrentPanel(Filament::getPanel('applicant'));

        return Livewire::actingAs($applicant)->test(Dashboard::class, ['sourceApplicationId' => $application->id]);
    }

    private function decide(AdmissionApplication $application, string $decision): void
    {
        AdmissionDecision::factory()->create(['admission_application_id' => $application->id, 'decision' => $decision]);
        $application->update(['application_state' => $decision === AdmissionDecision::DecisionAdmitted ? AdmissionApplication::StateAdmitted : AdmissionApplication::StateNotAdmitted]);
    }

    /** @return array{AdmissionApplication, User} */
    private function application(bool $submitted = true, bool $preliminaryEvidence = false): array
    {
        $applicant = User::factory()->create(['status' => User::StatusActive]);
        $applicant->assignRole('applicant');
        $cycle = AdmissionCycle::factory()->published()->create(['term_id' => Term::query()->value('id') ?? Term::factory()->create()->id, 'opens_at' => now()->subDay(), 'closes_at' => now()->addDay(), 'correction_closes_at' => now()->addDays(2)]);
        $application = AdmissionApplication::factory()->submitted()->create(['user_id' => $applicant->id, 'admission_cycle_id' => $cycle->id, 'application_state' => $submitted ? AdmissionApplication::StateSubmitted : AdmissionApplication::StateDraft]);
        $set = AdmissionRequirementSet::factory()->create(['admission_cycle_id' => $cycle->id, 'application_path' => $application->application_path]);
        AdmissionRequirement::factory()->create(['admission_requirement_set_id' => $set->id, 'label' => 'School review copy', 'requires_preliminary_evidence' => true, 'due_stage' => $preliminaryEvidence ? AdmissionRequirement::DuePreliminaryReview : AdmissionRequirement::DueEnrollmentReadiness]);
        $set->update(['state' => AdmissionRequirementSet::StatePublished, 'published_at' => now(), 'effective_at' => now()]);
        if ($submitted) {
            $version = ApplicationSubmissionVersion::factory()->create(['admission_application_id' => $application->id, 'admission_requirement_set_id' => $set->id, 'submitted_by' => $applicant->id]);
            $application->update(['current_submission_version_id' => $version->id]);
        }

        return [$application->fresh(), $applicant];
    }

    private function registrar(): User
    {
        $registrar = User::factory()->create(['status' => User::StatusActive]);
        $registrar->assignRole(User::StaffRoleRegistrar);

        return $registrar;
    }
}
