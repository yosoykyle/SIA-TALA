<?php

namespace Tests\Feature;

use App\Actions\Calendar\ActivateTermCalendarPackage;
use App\Actions\Scheduling\PrepareTermClassProposal;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermClassProposal;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\OperationalEvent;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TermTeachingGridRow;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue66TermPreparationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('staff'));
    }

    public function test_core_calendar_records_actor_without_optional_reference_date_or_consumer_dates(): void
    {
        $actor = $this->registrar();
        $package = TermCalendarPackage::factory()->create(['authority_reference' => null, 'authority_date' => null, 'faculty_availability_due_at' => null]);
        TermTeachingGridRow::factory()->create(['term_calendar_package_id' => $package->id, 'day_of_week' => 1]);
        $active = app(ActivateTermCalendarPackage::class)->execute($package, $actor);

        $this->assertNull($active->authority_reference);
        $this->assertSame($actor->id, $active->recorded_by);
        $this->assertNotNull($active->activated_at);
        $this->assertCount(0, $active->windows);
    }

    public function test_calendar_editing_dialogs_render_outside_the_closed_source_details(): void
    {
        [$term, , , $actor] = $this->source();
        $page = Livewire::actingAs($actor)->test(TermCalendar::class, ['termId' => $term->id]);
        $document = new \DOMDocument;
        @$document->loadHTML($page->html());
        $xpath = new \DOMXPath($document);
        $host = $xpath->query('//*[@*[name()="wire:partial" and .="action-modals"]]')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $host);
        $this->assertSame(0, $xpath->query('ancestor::details', $host)->length, 'Calendar editing must open independently of its source disclosure.');

        foreach (['recordCalendarPackage', 'addOperationalDates'] as $action) {
            $page->mountAction($action)->assertActionMounted($action)->assertMountedActionModalSee($action === 'recordCalendarPackage' ? 'Classes start' : 'Approved activity dates');
            $page->unmountAction();
        }
    }

    public function test_full_proposal_confirms_shared_and_split_classes_across_curriculum_years_atomically(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();
        $second = TermCohort::factory()->create(['term_id' => $term->id, 'program_id' => $cohort->program_id, 'curriculum_version_id' => $cohort->curriculum_version_id, 'confirmed_count' => 8]);
        $later = CurriculumEntry::factory()->create(['curriculum_version_id' => $entry->curriculum_version_id, 'course_specification_id' => $entry->course_specification_id, 'year_level' => '2', 'term_type' => $term->type]);
        $proposal = app(PrepareTermClassProposal::class);
        $preview = $proposal->preview($term);
        $this->assertContains($later->id, array_column($preview['catalog'], 'id'));
        $this->assertSame([$entry->id, $later->id], array_column($preview['classes'], 'entry_id'));
        $this->assertSame(['skip', 'skip'], array_column($preview['classes'], 'action'));
        $this->assertNull($preview['classes'][0]['capacity']);
        $this->assertSame([], $preview['classes'][0]['cohorts']);
        $row = $this->row($entry, $cohort, 'SYNTH66-SHARED', 12);
        $row['cohorts'][] = ['id' => $second->id, 'count' => 8];
        $result = $proposal->confirm($term, $actor, [$row, $this->row($entry, $cohort, 'SYNTH66-SPLIT', 18)], $preview['token']);

        $this->assertSame(['confirmed' => 2, 'cancelled' => 0], $result);
        $this->assertCount(2, Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))->whereNotNull('confirmed_at')->get());
        $this->assertSame(2, Section::query()->where('code', 'SYNTH66-SHARED')->firstOrFail()->cohorts()->count());
        $this->assertSame(0, SchedulingDemand::query()->count());
    }

    public function test_invalid_additional_authority_or_stale_proposal_saves_no_class(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();
        $service = app(PrepareTermClassProposal::class);
        $preview = $service->preview($term);
        $bad = [...$this->row($entry, $cohort, 'SYNTH66-BAD'), 'source' => Section::SourceAdditional];
        try {
            $service->confirm($term, $actor, [$this->row($entry, $cohort, 'SYNTH66-VALID'), $bad], $preview['token']);
            $this->fail('The complete transaction must reject missing Additional authority.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('sections', ['code' => 'SYNTH66-VALID']);
        }
        $cohort->update(['confirmed_count' => 29]);
        try {
            $service->confirm($term, $actor, [$this->row($entry, $cohort, 'SYNTH66-STALE')], $preview['token']);
            $this->fail('Changed demand must invalidate the preview.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('proposal', $exception->errors());
        }
        $this->assertDatabaseMissing('sections', ['code' => 'SYNTH66-STALE']);
    }

    public function test_cancellation_and_other_proposal_rows_are_atomic_and_keep_the_actor_and_reason(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();
        $service = app(PrepareTermClassProposal::class);
        $service->confirm($term, $actor, [$this->row($entry, $cohort, 'SYNTH66-CANCEL')], $service->preview($term)['token']);
        $class = Section::query()->where('code', 'SYNTH66-CANCEL')->sole();
        $preview = $service->preview($term);
        $cancellation = [...$preview['classes'][0], 'action' => 'cancel', 'cancellation_reason' => 'Recorded demand no longer requires this class.'];

        try {
            $service->confirm($term, $actor, [$cancellation, [...$this->row($entry, $cohort, 'SYNTH66-INVALID'), 'source' => Section::SourceAdditional]], $preview['token']);
            $this->fail('An invalid row must roll back the cancellation and its audit entry.');
        } catch (ValidationException) {
            $this->assertSame(Section::StatePlanned, $class->fresh()->state);
            $this->assertDatabaseMissing('activity_log', ['description' => 'class_cancelled', 'subject_id' => $class->id]);
        }

        $this->assertSame(['confirmed' => 0, 'cancelled' => 1], $service->confirm($term, $actor, [$cancellation], $preview['token']));
        $this->assertSame(Section::StateCancelled, $class->fresh()->state);
        $this->assertDatabaseHas('activity_log', ['description' => 'class_cancelled', 'subject_id' => $class->id, 'causer_id' => $actor->id]);
    }

    public function test_split_counts_cannot_duplicate_the_same_learners_and_externally_arranged_course_needs_no_meetings(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();
        $service = app(PrepareTermClassProposal::class);
        try {
            $service->confirm($term, $actor, [$this->row($entry, $cohort, 'SYNTH66-A', 20), $this->row($entry, $cohort, 'SYNTH66-B', 20)], $service->preview($term)['token']);
            $this->fail('Duplicated cohort counts must roll back the entire proposal.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('sections', ['code' => 'SYNTH66-A']);
        }
        $entry->courseSpecification->update(['scheduling_treatment' => CourseSpecification::SchedulingExternallyArranged]);
        $entry->courseSpecification->components()->delete();
        $service->confirm($term, $actor, [$this->row($entry, $cohort, 'SYNTH66-EXTERNAL')], $service->preview($term)['token']);
        $this->assertDatabaseHas('sections', ['code' => 'SYNTH66-EXTERNAL']);
        $this->assertSame(0, SchedulingDemand::query()->count());
    }

    public function test_resources_distinguish_no_request_from_delivered_request_without_requiring_classes(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();
        $faculty = User::factory()->create();
        Role::query()->firstOrCreate(['name' => User::StaffRoleFaculty, 'guard_name' => 'web']);
        $faculty->assignRole(User::StaffRoleFaculty);
        FacultyQualification::factory()->create(['faculty_user_id' => $faculty->id, 'course_id' => $entry->courseSpecification->course_id]);
        Livewire::actingAs($actor)->test(TermResources::class, ['termId' => $term->id])->assertSee($faculty->name)->assertSee('Not requested')->assertDontSee('Awaiting response');
        OperationalEvent::factory()->create(['event_type' => OperationalEvent::TypeFacultyAvailabilityRequestedEmail, 'related_record_type' => TermCalendarPackage::class,
            'related_record_id' => $term->calendarPackages()->where('state', 'Active')->value('id'), 'user_id' => $faculty->id,
            'status' => OperationalEvent::StatusProcessed, 'sent_at' => now()]);
        Livewire::actingAs($actor)->test(TermResources::class, ['termId' => $term->id])->assertSee('Awaiting response');
        Livewire::actingAs($actor)->test(TermClassProposal::class, ['termId' => $term->id])->assertSee('Term class decisions')->assertSee($entry->courseSpecification->title);
    }

    public function test_course_decision_keeps_class_fields_closed_until_the_course_is_included(): void
    {
        [$term, $entry, , $actor] = $this->source();

        $proposal = Livewire::actingAs($actor)->test(TermClassProposal::class, ['termId' => $term->id])
            ->assertSee($entry->courseSpecification->title)
            ->assertSee('Do not add a class')
            ->assertDontSee('Approved capacity')
            ->set('proposal.classes.0.action', 'confirm')
            ->assertSee('Approved capacity')
            ->assertSee('Student sections served')
            ->assertSee('Link a student section')
            ->assertSee('Review class proposal');

        $this->assertSame('confirm', $proposal->get('proposal.classes.0.action'));
        $this->assertSame(0, Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))->count());
    }

    public function test_approved_curriculum_dialog_groups_all_entries_with_meeting_and_existing_class_facts(): void
    {
        [$term, $entry, , $actor] = $this->source();
        CurriculumEntry::factory()->create([
            'curriculum_version_id' => $entry->curriculum_version_id,
            'course_specification_id' => $entry->course_specification_id,
            'year_level' => '2',
            'term_type' => Term::TypeSecondSemester,
        ]);

        Livewire::actingAs($actor)->test(TermClassProposal::class, ['termId' => $term->id])
            ->assertSee('2 approved entries')
            ->mountAction('viewApprovedCurriculum')
            ->assertActionMounted('viewApprovedCurriculum')
            ->assertMountedActionModalSee('For this Term type')
            ->assertMountedActionModalSee('Other Term types · Additional approval required')
            ->assertMountedActionModalSee($entry->courseSpecification->title)
            ->assertMountedActionModalSee($entry->courseSpecification->course->code)
            ->assertMountedActionModalSee(CourseComponent::meetingPatternOptions()[$entry->courseSpecification->components->first()->meeting_pattern])
            ->assertMountedActionModalSee('Existing classes')
            ->assertMountedActionModalSee('Close');

        $this->assertSame(0, Section::query()->count());
    }

    public function test_student_section_action_recovers_to_class_preparation_until_a_class_is_linked(): void
    {
        [$term, $entry, $cohort, $actor] = $this->source();

        Livewire::actingAs($actor)->test(TermClasses::class, ['termId' => $term->id, 'classesView' => 'cohorts'])
            ->searchTable($cohort->program->name)->assertCanSeeTableRecords([$cohort])
            ->searchTable('')
            ->assertSee('Prepare classes')->assertDontSee('View classes');

        $proposal = app(PrepareTermClassProposal::class);
        $preview = $proposal->preview($term);
        $proposal->confirm($term, $actor, [$this->row($entry, $cohort, 'SYNTH66-LINKED')], $preview['token']);

        Livewire::actingAs($actor)->test(TermClasses::class, ['termId' => $term->id, 'classesView' => 'cohorts'])
            ->assertSee('View classes')->assertDontSee('Prepare classes');

        $class = Section::query()->where('code', 'SYNTH66-LINKED')->sole();
        Livewire::actingAs($actor)->test(TermClasses::class, ['termId' => $term->id, 'classesView' => 'cohorts'])
            ->mountTableAction('linkedClasses', $cohort)
            ->assertMountedActionModalSee($class->code)
            ->assertMountedActionModalSeeHtml('href="'.SectionResource::getUrl('view', ['record' => $class, 'termId' => $term->id]).'"')
            ->assertSet('mountedActions.0.hasUnsavedChangesAlert', false);
    }

    public function test_retained_candidate_explains_required_review_before_another_attempt(): void
    {
        [$term, , , $actor] = $this->source();
        $run = ScheduleGenerationRun::factory()->create([
            'term_id' => $term->id,
            'status' => ScheduleGenerationRun::StatusUnderReview,
            'candidate_state' => null,
        ]);

        Livewire::actingAs($actor)->test(TermTimetable::class, [
            'termId' => $term->id,
            'viewTab' => 'generate',
            'selectedRunId' => $run->id,
        ])->assertSee('Review or reject this retained candidate before starting another attempt.')
            ->assertActionHidden('generateTimetable');
    }

    public function test_missing_solver_evidence_is_unavailable_and_scaled_quality_uses_load_units(): void
    {
        $run = ScheduleGenerationRun::factory()->create(['diagnostics' => null]);
        $this->assertFalse($run->reviewEvidence()['validated']);
        $this->assertSame('Hard validity not established', $run->reviewEvidence()['conflicts']);
        $run->diagnostics = ['solver_result' => ['summary' => ['status' => 'accepted']]];
        $this->assertFalse($run->reviewEvidence()['validated']);
        $run->quality_measures = ['faculty_load_imbalance' => 600, 'faculty_idle_time' => null];
        $this->assertSame('6.00 load units', $run->qualityPresentation()[2]['value']);
        $this->assertSame('Not measured', $run->qualityPresentation()[3]['value']);
        $run->forceFill(['status' => ScheduleGenerationRun::StatusFailed, 'candidate_key' => null, 'published_at' => null,
            'diagnostics' => ['solver_dispatch' => ['failure' => ['code' => 'INFEASIBLE', 'retryable' => true, 'final' => true]]]]);
        $this->assertFalse($run->canRetrySolver());
    }

    private function registrar(): User
    {
        $role = Role::query()->firstOrCreate(['name' => User::StaffRoleRegistrar, 'guard_name' => 'web']);
        $role->givePermissionTo(Permission::findOrCreate('manage-schedules', 'web'));
        $actor = User::factory()->create();
        $actor->assignRole($role);

        return $actor;
    }

    /** @return array{Term,CurriculumEntry,TermCohort,User} */
    private function source(): array
    {
        $actor = $this->registrar();
        $package = TermCalendarPackage::factory()->create(['state' => TermCalendarPackage::StateActive, 'activated_at' => now()]);
        $term = $package->term;
        $term->update(['state' => Term::StateActive]);
        TermTeachingGridRow::factory()->create(['term_calendar_package_id' => $package->id]);
        $curriculum = CurriculumVersion::factory()->create(['state' => CurriculumVersion::StateActive]);
        $revision = CourseSpecification::factory()->create(['state' => CourseSpecification::StateActive, 'allowed_modalities' => [TermOffering::ModalityOnline]]);
        CourseComponent::factory()->for($revision, 'courseSpecification')->create();
        $entry = CurriculumEntry::factory()->for($curriculum, 'curriculumVersion')->create(['course_specification_id' => $revision->id, 'year_level' => '1', 'term_type' => $term->type]);
        $cohort = TermCohort::factory()->create(['term_id' => $term->id, 'program_id' => $curriculum->program_id, 'curriculum_version_id' => $curriculum->id]);

        return [$term, $entry, $cohort, $actor];
    }

    /** @return array<string,mixed> */
    private function row(CurriculumEntry $entry, TermCohort $cohort, string $code, int $count = 30): array
    {
        return ['action' => 'confirm', 'entry_id' => $entry->id, 'code' => $code, 'capacity' => 40, 'source' => Section::SourceRegular,
            'modality' => TermOffering::ModalityOnline, 'cohorts' => [['id' => $cohort->id, 'count' => $count]]];
    }
}
