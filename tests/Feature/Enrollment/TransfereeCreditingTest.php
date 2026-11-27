<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Enrollment\CorrectRegistrationCreditEvaluation;
use App\Actions\Enrollment\RecordRegistrationCreditEvaluation;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Enrollment\RegistrationClassChoices;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\Enrollments\Schemas\TransferCreditingForm;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\DocumentEvidence;
use App\Models\Enrollment;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationCreditLine;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section as ClassSection;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TransfereeCreditingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registrar_saves_credited_bridging_and_need_to_take_rows_from_the_crediting_table(): void
    {
        [$case, $curriculum, $entries] = $this->transfereeCase();
        [$credited, $fewerUnits, $needed] = $entries;

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($this->staff(User::StaffRoleRegistrar))
            ->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->mountAction('recordAcademicEvaluation')
            ->assertMountedActionModalSee('Is the previous school’s curriculum the same as SIA’s?')
            ->assertMountedActionModalSee('Year 1 · First Semester')
            ->assertMountedActionModalSee($needed->courseSpecification->course->code)
            ->assertMountedActionModalDontSee('Credit with bridging');
        $component->setActionData([
            'curriculum_version_id' => $curriculum->id,
            'same_curriculum' => 'different',
            'rows' => [
                'e'.$credited->id => ['status' => 'credited', 'tor_grade' => '1.75', 'source_course_code' => 'CS 101', 'source_course_title' => 'Computer Fundamentals', 'source_units' => '3'],
                'e'.$fewerUnits->id => ['status' => 'credited', 'tor_grade' => '2.00', 'source_course_code' => 'MATH 1', 'source_course_title' => 'College Algebra', 'source_units' => '2'],
                'e'.$needed->id => ['status' => 'need'],
            ],
            'authority_reference' => 'SYN-CREDITING-MEMO',
            'approved_on' => today()->toDateString(),
        ]);
        $component->callMountedAction()->assertHasNoActionErrors()->assertNotified('Academic evaluation recorded');

        $evaluation = RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->sole();
        $this->assertSame([$credited->id, $fewerUnits->id], $evaluation->credited_curriculum_entry_ids);
        $this->assertFalse($evaluation->same_curriculum);
        $lines = $evaluation->creditLines()->get()->keyBy('curriculum_entry_id');
        $this->assertSame(['CS 101', '1.75'], [$lines[$credited->id]->source_course_code, $lines[$credited->id]->tor_grade]);
        $this->assertSame('2.00', $lines[$fewerUnits->id]->source_units, 'A subject with fewer TOR units is still credited in full.');
        $this->assertSame('Transfer credit from MATH 1', $lines[$fewerUnits->id]->transcriptRemark());
    }

    public function test_credited_rows_need_a_tor_grade_and_source_unless_the_curriculum_is_the_same(): void
    {
        [$case, $curriculum, $entries] = $this->transfereeCase();
        $entry = $entries[0];
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $submission = (int) $case->admissionApplication->current_submission_version_id;
        $record = fn (array $line, ?bool $same, array $ids = []): RegistrationCreditEvaluation => app(RecordRegistrationCreditEvaluation::class)->execute(
            $case->fresh(), $registrar, $ids === [] ? [$entry->id] : $ids, 'SYN-CREDITING-MEMO', CarbonImmutable::today(), $submission,
            $curriculum->id, $case->fresh()->lock_version, creditLines: $line === [] ? [] : [$entry->id => $line], sameCurriculum: $same,
        );

        $failures = [
            'missing line' => [[], false],
            'missing TOR grade' => [['source_course_code' => 'CS 101', 'source_course_title' => 'Fundamentals'], false],
            'missing source on a different curriculum' => [['tor_grade' => '1.50'], false],
            'non-numeric units' => [['tor_grade' => '1.50', 'source_course_code' => 'CS 101', 'source_course_title' => 'Fundamentals', 'source_units' => 'three'], false],
        ];
        foreach ($failures as $failure => [$line, $same]) {
            try {
                $record($line, $same);
                $this->fail('Invalid crediting accepted: '.$failure);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('credit_lines', $exception->errors(), $failure);
            }
        }
        $this->assertSame(0, RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->count());

        $evaluation = $record(['tor_grade' => '92'], true);
        $line = $evaluation->creditLines()->sole();
        $this->assertTrue($evaluation->same_curriculum);
        $this->assertSame([$entry->courseSpecification->course->code, $entry->courseSpecification->title, '3.00', '92'],
            [$line->source_course_code, $line->source_course_title, $line->source_units, $line->tor_grade]);
    }

    public function test_registrar_correction_after_official_enrollment_counts_immediately_and_keeps_history(): void
    {
        $student = StudentProfile::factory()->create();
        [$first, $second] = collect(range(1, 2))->map(fn (int $sequence): CurriculumEntry => $this->entry($student->curriculumVersion, $sequence))->all();
        $enrollment = Enrollment::factory()->for($student)->create([
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled, 'status' => 'officially_enrolled', 'officially_enrolled_at' => now(),
        ]);
        $original = RegistrationCreditEvaluation::factory()->create([
            'enrollment_id' => $enrollment->id, 'curriculum_version_id' => $student->curriculum_version_id,
            'credited_curriculum_entry_ids' => [$first->id],
        ]);
        RegistrationCreditLine::factory()->create(['registration_credit_evaluation_id' => $original->id, 'curriculum_entry_id' => $first->id]);
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $correct = app(CorrectRegistrationCreditEvaluation::class);
        $line = [$second->id => ['tor_grade' => '2.25', 'source_course_code' => 'IT 102', 'source_course_title' => 'Programming 2']];

        try {
            $correct->execute($student, $this->staff(User::StaffRoleAccounting), $line, false, 'SYN-CORRECTION', CarbonImmutable::today(), $original->id);
            $this->fail('Only the Registrar may correct crediting.');
        } catch (AuthorizationException) {
            $this->assertSame([$first->id], RegistrationCreditEvaluation::creditedEntryIdsForStudent($student));
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($registrar)->test(ViewStudentProfile::class, ['record' => $student->id])
            ->set('recordTab', 'academic')->assertSee('Correct crediting')
            ->mountAction('correctCrediting');
        $component->setActionData([
            'same_curriculum' => 'different',
            'rows' => ['e'.$first->id => ['status' => 'need'], 'e'.$second->id => ['status' => 'credited', ...$line[$second->id]]],
            'authority_reference' => 'SYN-CORRECTION', 'approved_on' => today()->toDateString(),
        ]);
        $component->callMountedAction()->assertHasNoActionErrors()->assertNotified('Crediting corrected');

        $this->assertSame([$second->id], RegistrationCreditEvaluation::creditedEntryIdsForStudent($student));
        $statuses = collect(app(CurriculumEvaluation::class)->forStudent($student)['required'])
            ->mapWithKeys(fn (array $row): array => [$row['curriculum_entry']->id => $row['status']]);
        $this->assertSame(['Not taken', 'Approved credit'], [$statuses[$first->id], $statuses[$second->id]]);
        // Credited subjects carry a clear indicator and their TOR source, apart from subjects passed at SIA.
        $credited = collect(app(CurriculumEvaluation::class)->forStudent($student)['required'])->firstWhere('curriculum_entry.id', $second->id);
        $this->assertSame('credited', $credited['checklist_state']);
        $this->assertSame('Credited (transfer)', $credited['credit']['label']);
        $this->assertStringContainsString('From IT 102', $credited['credit']['detail']);
        $this->assertStringContainsString('TOR grade 2.25', $credited['credit']['detail']);
        $component->assertSee('Credited (transfer)')->assertSee('TOR grade 2.25');
        $this->assertTrue(app(RegistrationAcademicEligibilityQuery::class)->satisfiedCourseIds($student)->contains($second->courseSpecification->course_id));
        $this->assertSame(2, RegistrationCreditEvaluation::query()->where('enrollment_id', $enrollment->id)->count());

        try {
            $correct->execute($student, $registrar, $line, false, 'SYN-STALE', CarbonImmutable::today(), $original->id);
            $this->fail('A stale correction form must not replace the newer crediting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evaluation', $exception->errors());
            $this->assertSame(2, RegistrationCreditEvaluation::query()->where('enrollment_id', $enrollment->id)->count());
        }
    }

    /** @return array{0: Enrollment, 1: CurriculumVersion, 2: list<CurriculumEntry>} */
    public function test_crediting_table_lists_years_and_semesters_in_curriculum_order(): void
    {
        [$case, $curriculum] = $this->transfereeCase();
        foreach ([['Second Year', 'Second Semester', 1], ['First Year', 'Second Semester', 1], ['Second Year', 'First Semester', 1], ['First Year', 'First Semester', 2]] as [$year, $term, $sequence]) {
            CurriculumEntry::factory()->for($curriculum)->create([
                'course_specification_id' => CourseSpecification::factory()->create(['credit_units' => 3.00])->id,
                'year_level' => $year, 'term_label' => $term, 'sequence' => $sequence,
            ]);
        }

        $termSections = new \ReflectionMethod(TransferCreditingForm::class, 'termSections');
        $headings = collect($termSections->invoke(null, $curriculum->id, null))
            ->map(fn (Section $section): string => (string) $section->getHeading())
            ->all();

        $this->assertSame(['Year 1 · First Semester', 'First Year · First Semester', 'First Year · Second Semester', 'Second Year · First Semester', 'Second Year · Second Semester'], $headings);
    }

    public function test_same_curriculum_fills_tor_code_title_and_units_and_different_clears_them(): void
    {
        [$case, $curriculum, $entries] = $this->transfereeCase();
        [$first, $second] = $entries;
        $first->load('courseSpecification.course');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($this->staff(User::StaffRoleRegistrar))
            ->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->mountAction('recordAcademicEvaluation')
            ->setActionData(['curriculum_version_id' => $curriculum->id, "rows.e{$second->id}.source_course_code" => 'OLD 200'])
            ->setActionData(['same_curriculum' => 'same']);

        $component->assertActionDataSet([
            "rows.e{$first->id}.source_course_code" => $first->courseSpecification->course->code,
            "rows.e{$first->id}.source_course_title" => $first->courseSpecification->title,
            "rows.e{$first->id}.source_units" => '3.00',
            "rows.e{$second->id}.source_course_code" => 'OLD 200',
        ]);

        $component->setActionData(['same_curriculum' => 'different'])
            ->assertActionDataSet([
                "rows.e{$first->id}.source_course_code" => null,
                "rows.e{$first->id}.source_course_title" => null,
                "rows.e{$second->id}.source_course_code" => 'OLD 200',
            ]);
    }

    public function test_crediting_opens_full_screen_with_the_tor_preview_first(): void
    {
        [$case] = $this->transfereeCase();
        $application = $case->admissionApplication;
        $set = AdmissionRequirementSet::factory()->create(['admission_cycle_id' => $application->admission_cycle_id, 'application_path' => $application->application_path]);
        $credential = AdmissionRequirement::factory()->create(['admission_requirement_set_id' => $set->id, 'code' => 'HD', 'label' => 'Honorable Dismissal']);
        $transcript = AdmissionRequirement::factory()->create(['admission_requirement_set_id' => $set->id, 'code' => 'TOR', 'label' => 'Transcript of Records']);
        DocumentEvidence::factory()->canonical($application, $credential)->create();
        $tor = DocumentEvidence::factory()->canonical($application, $transcript)->create();

        $copies = TransferCreditingForm::previewCopies($application->fresh());
        $this->assertSame(['Transcript of Records', 'Honorable Dismissal'], array_column($copies, 'label'));
        $this->assertSame(route('admissions.evidence.view', ['evidence' => $tor->id]), $copies[0]['view_url']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($this->staff(User::StaffRoleRegistrar))
            ->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->mountAction('recordAcademicEvaluation')
            ->assertMountedActionModalSee('Transcript of Records')
            ->assertMountedActionModalSee('Honorable Dismissal');
        $this->assertSame(Width::Screen, $component->instance()->getMountedAction()->getModalWidth());
    }

    public function test_credited_subjects_are_excluded_from_class_choices_and_choose_subjects_picker(): void
    {
        [$case, $curriculum, $entries] = $this->transfereeCase();
        [$credited, $needed, $alsoNeeded] = $entries;
        $registrar = $this->staff(User::StaffRoleRegistrar);

        $timetable = PublishedTimetableVersion::factory()->create([
            'term_id' => $case->term_id,
            'state' => PublishedTimetableVersion::StatePublished,
        ]);

        $sections = collect([$credited, $needed, $alsoNeeded])->mapWithKeys(function (CurriculumEntry $entry, int $index) use ($case, $timetable): array {
            $entry->courseSpecification->update(['scheduling_treatment' => CourseSpecification::SchedulingRecurring]);
            $offering = TermOffering::factory()->create([
                'term_id' => $case->term_id,
                'curriculum_entry_id' => $entry->id,
                'state' => TermOffering::StateScheduled,
            ]);
            $section = ClassSection::factory()->create([
                'term_offering_id' => $offering->id,
                'code' => 'CRED-SEC-'.($index + 1),
                'state' => ClassSection::StateOpen,
                'capacity' => 30,
            ]);
            PublishedTimetableMeeting::factory()->for($timetable, 'timetableVersion')->create([
                'section_id' => $section->id,
            ]);

            return [$entry->id => $section];
        });

        app(RecordRegistrationCreditEvaluation::class)->execute(
            $case,
            $registrar,
            [$credited->id],
            'SYN-CREDITING-PICKER',
            CarbonImmutable::today(),
            (int) $case->admissionApplication->current_submission_version_id,
            $curriculum->id,
            $case->lock_version,
            creditLines: [$credited->id => ['tor_grade' => '1.75', 'source_course_code' => 'CS 101', 'source_course_title' => 'Intro to Computing']],
            sameCurriculum: false,
        );

        $case->refresh();
        $choices = app(RegistrationClassChoices::class);
        $availableIds = $choices->forCase($case)['sections']->pluck('id')->all();
        $options = $choices->sectionOptions($case);

        $this->assertNotContains($sections[$credited->id]->id, $availableIds);
        $this->assertContains($sections[$needed->id]->id, $availableIds);
        $this->assertContains($sections[$alsoNeeded->id]->id, $availableIds);
        $this->assertArrayNotHasKey($sections[$credited->id]->id, $options);
        $this->assertArrayHasKey($sections[$needed->id]->id, $options);
        $this->assertArrayHasKey($sections[$alsoNeeded->id]->id, $options);

        // An existing unconfirmed proposal referencing a now-credited class must not crash Choose subjects.
        $proposal = RegistrationProposalVersion::factory()->create([
            'enrollment_id' => $case->id,
            'curriculum_version_id' => $curriculum->id,
            'published_timetable_version_id' => $timetable->id,
            'state' => RegistrationProposalVersion::StateDraft,
            'prepared_by' => $registrar->id,
        ]);
        foreach ([$credited->id, $needed->id] as $sequence => $entryId) {
            RegistrationProposalItem::factory()->create([
                'registration_proposal_version_id' => $proposal->id,
                'term_offering_id' => $sections[$entryId]->term_offering_id,
                'section_id' => $sections[$entryId]->id,
                'sequence' => $sequence + 1,
            ]);
        }
        $case->update(['current_proposal_version_id' => $proposal->id]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($registrar)
            ->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->mountAction('prepareProposal')
            ->assertMountedActionModalDontSee($sections[$credited->id]->code)
            ->assertMountedActionModalSee($sections[$needed->id]->code)
            ->assertMountedActionModalSee($sections[$alsoNeeded->id]->code)
            ->assertActionDataSet(['section_ids' => [$sections[$needed->id]->id]]);
    }

    private function transfereeCase(): array
    {
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $entries = collect(range(1, 3))->map(fn (int $sequence): CurriculumEntry => $this->entry($curriculum, $sequence))->all();
        $application = AdmissionApplication::factory()->create([
            'program_id' => $curriculum->program_id,
            'application_path' => AdmissionApplication::PathTransferee,
        ]);
        $submission = ApplicationSubmissionVersion::factory()->for($application, 'application')->create();
        $application->update(['current_submission_version_id' => $submission->id]);
        $case = Enrollment::factory()->create([
            'credential_user_id' => $application->user_id,
            'admission_application_id' => $application->id,
            'student_profile_id' => null,
            'term_id' => Term::factory()->create(['state' => Term::StateActive])->id,
            'selection_basis' => Enrollment::SelectionIndividuallyAdvised,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
        ]);

        return [$case, $curriculum, $entries];
    }

    private function entry(CurriculumVersion $curriculum, int $sequence): CurriculumEntry
    {
        return CurriculumEntry::factory()->for($curriculum)->create([
            'course_specification_id' => CourseSpecification::factory()->create(['credit_units' => 3.00])->id,
            'year_level' => '1', 'term_label' => 'First Semester', 'sequence' => $sequence,
        ]);
    }

    private function staff(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
