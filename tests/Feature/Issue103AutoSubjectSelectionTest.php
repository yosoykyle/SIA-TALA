<?php

namespace Tests\Feature;

use App\Actions\Enrollment\AdvisedSubjectSuggestion;
use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\PrepareStandardRegistration;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Models\Assessment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\IrregularEnrollmentRequest;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue103AutoSubjectSelectionTest extends TestCase
{
    use DatabaseTransactions;

    private StudentProfile $profile;

    private Term $term;

    private PublishedTimetableVersion $timetable;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['student', User::StaffRoleRegistrar, User::StaffRoleAccounting] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $this->profile = StudentProfile::factory()->create();
        $this->profile->user->update(['status' => User::StatusActive]);
        $this->profile->user->assignRole('student');
        $this->term = Term::factory()->create(['type' => Term::TypeFirstSemester, 'state' => Term::StateActive]);
        $package = TermCalendarPackage::factory()->for($this->term)->create(['state' => TermCalendarPackage::StateActive]);
        TermCalendarWindow::factory()->for($package, 'package')->create([
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => now()->subDay()->toDateString(),
            'closes_on' => now()->addDays(7)->toDateString(),
        ]);
        $this->timetable = PublishedTimetableVersion::factory()->for($this->term)->create(['state' => PublishedTimetableVersion::StatePublished]);
    }

    public function test_suggestion_follows_curriculum_order_explains_left_out_subjects_and_approval_reaches_the_student_after_assessment(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $accounting = $this->staff(User::StaffRoleAccounting);
        $foundations = $this->offer('Foundations I', 3, 'First Year', 1, [1, '08:00', '09:30']);
        $ethics = $this->offer('Ethics', 3, 'First Year', 2, [1, '08:30', '10:00']);
        $writing = $this->offer('Academic Writing', 4, 'First Year', 3, [3, '08:00', '09:30']);
        $systems = $this->offer('Systems Analysis', 3, 'Second Year', 1, [2, '10:00', '11:30']);
        $networks = $this->offer('Networks', 3, 'Second Year', 2, [4, '10:00', '11:30']);
        $this->offer('Database Systems', 3, 'Second Year', 3, null);
        CourseRequirement::factory()->create(['course_specification_id' => $networks->termOffering->curriculumEntry->course_specification_id]);
        $case = Enrollment::factory()->for($this->profile)->for($this->term)->create([
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'credential_user_id' => $this->profile->user_id,
        ]);

        $suggestion = app(AdvisedSubjectSuggestion::class)->forCase($case);

        $this->assertSame(AdvisedSubjectSuggestion::SourceSuggested, $suggestion['source']);
        $this->assertSame([$foundations->id, $writing->id, $systems->id], $suggestion['section_ids']);
        $this->assertSame(10.0, $suggestion['selected_units']);
        $this->assertSame(10.0, $suggestion['normal_units']);
        $this->assertSame('First Year|First Semester|'.Term::TypeFirstSemester, $suggestion['curriculum_position']);
        $this->assertSame([
            'Ethics' => AdvisedSubjectSuggestion::ReasonClash,
            'Networks' => AdvisedSubjectSuggestion::ReasonPrerequisite,
            'Database Systems' => AdvisedSubjectSuggestion::ReasonNotOffered,
        ], collect($suggestion['left_out'])->pluck('reason', 'title')->all());
        $this->assertNotContains($ethics->id, $suggestion['section_ids']);
        $this->assertSame(0, $case->seatReservations()->count(), 'A suggestion reserves nothing.');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->assertSee('Suggested subjects')
            ->assertSee('Not included')
            ->assertSee(AdvisedSubjectSuggestion::ReasonClash)
            ->assertActionVisible('approveSuggestedSubjects')
            ->assertActionVisible('prepareProposal')
            ->callAction('approveSuggestedSubjects')
            ->assertNotified('Subjects approved and seats reserved');

        $case->refresh();
        $proposal = $case->currentProposalVersion;
        $this->assertSame(RegistrationProposalVersion::StateDraft, $proposal->state);
        $this->assertEqualsCanonicalizing([$foundations->id, $writing->id, $systems->id], $proposal->items->pluck('section_id')->all());
        $this->assertSame(3, $case->seatReservations()->count());

        app(RecordAuthorizedIndividualAssessment::class)->execute(
            $case->fresh(),
            $accounting,
            Assessment::CategoryIndividuallyAdvised,
            'SYN-ADVISED-103',
            CarbonImmutable::parse('2026-08-25', config('app.timezone')),
            [['code' => 'TUITION', 'label' => 'Advised tuition', 'amount' => '5000.00']],
            [[
                'code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'purpose' => 'Enrollment', 'amount' => '1000.00',
                'due_at' => now()->addDay()->toDateTimeString(), 'required_for_enrollment' => true,
            ], [
                'code' => 'REMAINING', 'label' => 'Remaining obligation', 'purpose' => 'TermPayment', 'amount' => '4000.00',
                'due_at' => now()->addMonth()->toDateTimeString(), 'required_for_enrollment' => false,
            ]],
        );

        $this->assertSame(RegistrationProposalVersion::StateIssued, $proposal->fresh()->state, 'The approved subjects go to the student once assessed.');
    }

    public function test_assessment_is_kept_and_registrar_sees_why_when_the_subjects_cannot_be_sent(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $section = $this->offer('Foundations I', 3, 'First Year', 1, [1, '08:00', '09:30']);
        $case = Enrollment::factory()->for($this->profile)->for($this->term)->create([
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'credential_user_id' => $this->profile->user_id,
        ]);
        $proposal = app(PrepareRegistrationProposal::class)->execute($case, $registrar, [$section->id], $case->lock_version);
        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $registrar);
        $this->profile->user->update(['status' => User::StatusInactive]);

        $assessment = app(RecordAuthorizedIndividualAssessment::class)->execute(
            $case->fresh(),
            $this->staff(User::StaffRoleAccounting),
            Assessment::CategoryIndividuallyAdvised,
            'SYN-ADVISED-103-B',
            CarbonImmutable::parse('2026-08-25', config('app.timezone')),
            [['code' => 'TUITION', 'label' => 'Advised tuition', 'amount' => '1000.00']],
            [[
                'code' => 'ENROLLMENT', 'label' => 'Enrollment obligation', 'purpose' => 'Enrollment', 'amount' => '1000.00',
                'due_at' => now()->addDay()->toDateTimeString(), 'required_for_enrollment' => true,
            ]],
        );

        $this->assertTrue($assessment->exists);
        $this->assertSame(RegistrationProposalVersion::StateDraft, $proposal->fresh()->state);
        $this->assertStringStartsWith(CreateAssessmentFromPublishedFeePlan::NotSentReasonPrefix, (string) $case->fresh()->status_reason);

        $this->profile->user->update(['status' => User::StatusActive]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->assertSee('Not sent automatically')
            ->assertActionVisible('issueProposal');
    }

    public function test_a_submitted_irregular_request_is_the_suggestion_as_requested(): void
    {
        $foundations = $this->offer('Foundations I', 3, 'First Year', 1, [1, '08:00', '09:30']);
        $systems = $this->offer('Systems Analysis', 3, 'Second Year', 1, [2, '10:00', '11:30']);
        $case = Enrollment::factory()->for($this->profile)->for($this->term)->create([
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'credential_user_id' => $this->profile->user_id,
        ]);
        IrregularEnrollmentRequest::factory()->create([
            'enrollment_id' => $case->id,
            'section_ids' => [$systems->id],
            'selected_units' => 3,
        ]);

        $suggestion = app(AdvisedSubjectSuggestion::class)->forCase($case);

        $this->assertSame(AdvisedSubjectSuggestion::SourceRequest, $suggestion['source']);
        $this->assertSame([$systems->id], $suggestion['section_ids']);
        $this->assertNotContains($foundations->id, $suggestion['section_ids']);
        $this->assertSame([AdvisedSubjectSuggestion::ReasonRequested], array_column($suggestion['picked'], 'reason'));
    }

    public function test_regular_case_takes_the_roomiest_valid_block_and_offers_no_manual_subject_choice(): void
    {
        $entry = CurriculumEntry::factory()->for($this->profile->curriculumVersion)->create([
            'year_level' => 'First Year', 'term_label' => 'First Semester', 'term_type' => $this->term->type, 'sequence' => 1,
        ]);
        $offering = TermOffering::factory()->for($this->term)->for($entry)->create(['state' => TermOffering::StateScheduled]);
        $blocks = [];
        foreach (['BSIT-1A' => 10, 'BSIT-1B' => 30] as $reference => $capacity) {
            $section = Section::factory()->for($offering, 'termOffering')->create(['code' => $reference, 'state' => Section::StateOpen, 'capacity' => $capacity]);
            $cohort = TermCohort::factory()->for($this->term)->create([
                'program_id' => $this->profile->program_id,
                'reference' => $reference,
                'state' => 'Confirmed',
                'confirmed_at' => now(),
            ]);
            $section->cohorts()->attach($cohort->id, ['expected_count' => $capacity]);
            PublishedTimetableMeeting::factory()->for($this->timetable, 'timetableVersion')->create(['section_id' => $section->id]);
            $blocks[$reference] = $section;
        }
        $case = Enrollment::factory()->for($this->profile)->for($this->term)->create();

        $this->assertSame([$blocks['BSIT-1B']->id], app(PrepareStandardRegistration::class)->sectionIds($case));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->staff(User::StaffRoleRegistrar))->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->assertActionHidden('prepareProposal')
            ->assertActionHidden('approveSuggestedSubjects');
    }

    /** @param array{int, string, string}|null $meeting */
    private function offer(string $title, int $units, string $year, int $sequence, ?array $meeting): ?Section
    {
        $entry = CurriculumEntry::factory()->for($this->profile->curriculumVersion)->create([
            'course_specification_id' => CourseSpecification::factory()->create(['title' => $title, 'credit_units' => $units])->id,
            'year_level' => $year,
            'term_label' => 'First Semester',
            'term_type' => $this->term->type,
            'sequence' => $sequence,
        ]);
        if ($meeting === null) {
            return null;
        }

        $section = Section::factory()->for(
            TermOffering::factory()->for($this->term)->for($entry)->create([
                'category' => TermOffering::CategoryRegular,
                'state' => TermOffering::StateScheduled,
            ]),
            'termOffering',
        )->create(['code' => strtoupper(substr($title, 0, 3)).'-'.$sequence, 'state' => Section::StateOpen, 'capacity' => 25]);
        PublishedTimetableMeeting::factory()->for($this->timetable, 'timetableVersion')->create([
            'section_id' => $section->id,
            'day_of_week' => $meeting[0],
            'starts_at' => $meeting[1].':00',
            'ends_at' => $meeting[2].':00',
        ]);

        return $section;
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
