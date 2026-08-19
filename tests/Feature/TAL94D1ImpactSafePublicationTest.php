<?php

namespace Tests\Feature;

use App\Actions\Scheduling\CandidateScheduleRowReviewService;
use App\Actions\Scheduling\SchedulePublicationImpactService;
use App\Actions\Scheduling\SchedulePublishService;
use App\Filament\Resources\ScheduleGenerationRuns\Pages\ViewScheduleGenerationRun;
use App\Models\CandidateScheduleRow;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\Enrollment;
use App\Models\FacultyQualification;
use App\Models\Room;
use App\Models\ScheduleGenerationRun;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\SectionMeeting;
use App\Models\StudentProfile;
use App\Models\StudentScheduleBinding;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL94D1ImpactSafePublicationTest extends TestCase
{
    use DatabaseTransactions;

    private SchedulePublicationImpactService $impactService;

    private SchedulePublishService $publisher;

    private User $faculty;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleFaculty,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->impactService = app(SchedulePublicationImpactService::class);
        $this->publisher = app(SchedulePublishService::class);
        $this->faculty = $this->staff(User::StaffRoleFaculty);
        $this->room = Room::factory()->create([
            'room_type' => Room::TypeLectureRoom,
            'capacity' => 100,
            'is_active' => true,
        ]);
    }

    public function test_impact_classifies_assignments_and_counts_affected_people(): void
    {
        $term = Term::factory()->create();
        $unchanged = $this->source($term);
        $changed = $this->source($term);
        $removed = $this->source($term);
        $added = $this->source($term);
        $secondFaculty = $this->staff(User::StaffRoleFaculty);
        $priorRun = $this->scheduleRun($term, ScheduleGenerationRun::StatusPublished, [
            'publication_version' => 1,
            'published_at' => now()->subDay(),
        ]);
        $unchangedMeeting = $this->officialMeeting($priorRun, $unchanged['demand'], '08:00:00', '10:00:00');
        $changedMeeting = $this->officialMeeting($priorRun, $changed['demand'], '10:00:00', '12:00:00');
        $this->officialMeeting($priorRun, $removed['demand'], '13:00:00', '15:00:00');
        $newRun = $this->scheduleRun($term);
        $this->candidate($newRun, $unchanged['demand'], startsAt: '08:00:00', endsAt: '10:00:00');
        $this->candidate($newRun, $changed['demand'], startsAt: '11:00:00', endsAt: '13:00:00');
        $this->candidate($newRun, $added['demand'], faculty: $secondFaculty, startsAt: '15:00:00', endsAt: '17:00:00');
        $this->activeBinding($term, $changed['offering'], $changedMeeting);

        $impact = $this->impactService->preview($newRun);

        $this->assertSame([
            'new_assignments' => 1,
            'changed_assignments' => 1,
            'removed_assignments' => 1,
            'unchanged_assignments' => 1,
            'affected_faculty' => 2,
            'active_bindings' => 1,
            'affected_students' => 1,
            'current_publication_version' => 1,
        ], $impact->toArray());
        $this->assertTrue($impact->blocksFullReplacement());
        $this->assertModelExists($unchangedMeeting);
    }

    public function test_binding_added_after_preview_blocks_replacement_without_mutation(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $source = $this->source($term);
        $priorRun = $this->scheduleRun($term, ScheduleGenerationRun::StatusPublished, [
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $priorMeeting = $this->officialMeeting($priorRun, $source['demand'], '08:00:00', '10:00:00');
        $newRun = $this->scheduleRun($term);
        $candidate = $this->candidate($newRun, $source['demand'], startsAt: '10:00:00', endsAt: '12:00:00');

        $this->assertSame(0, $this->impactService->preview($newRun)->activeBindings());
        $binding = $this->activeBinding($term, $source['offering'], $priorMeeting);

        try {
            $this->publisher->publish($newRun, $registrar);
            $this->fail('Publication with an active student binding was not blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication_impact', $exception->errors());
        }

        $this->assertSame(ScheduleGenerationRun::StatusPublished, $priorRun->fresh()->status);
        $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $newRun->fresh()->status);
        $this->assertSame(1, SectionMeeting::query()->count());
        $this->assertTrue($binding->fresh()->is_active);
        $this->assertSame('10:00:00', $candidate->fresh()->starts_at);
        $this->assertSame(0, DB::table('activity_log')
            ->where('event', 'schedule_generation_run_published')
            ->where('subject_id', $newRun->id)
            ->count());
    }

    public function test_warning_and_explicit_lower_quality_acceptance_require_a_reason(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $warningTerm = Term::factory()->create();
        $warningSource = $this->source($warningTerm);
        $warningRun = $this->scheduleRun($warningTerm);
        $this->candidate(
            $warningRun,
            $warningSource['demand'],
            status: CandidateScheduleRow::StatusWarning,
            warnings: [['key' => 'late_slot', 'message' => 'Late class slot.']],
        );

        $this->assertPublicationNoteRequired($warningRun, $registrar);
        $warningPublished = $this->publisher->publish($warningRun, $registrar, 'Accepted advisory warning.');
        $this->assertSame('Accepted advisory warning.', $warningPublished->publication_note);

        $qualityTerm = Term::factory()->create();
        $qualitySource = $this->source($qualityTerm);
        $qualityRun = $this->scheduleRun($qualityTerm);
        $this->candidate($qualityRun, $qualitySource['demand']);

        $this->assertPublicationNoteRequired($qualityRun, $registrar, true);
        $qualityPublished = $this->publisher->publish($qualityRun, $registrar, 'Accepted lower soft-quality result.', true);
        $activity = DB::table('activity_log')
            ->where('event', 'schedule_generation_run_published')
            ->where('subject_id', $qualityPublished->id)
            ->sole();
        $properties = json_decode($activity->properties, true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($properties['accepted_lower_quality']);
        $this->assertSame('Accepted lower soft-quality result.', $properties['publication_note']);
        $this->assertSame(1, $properties['impact']['new_assignments']);
    }

    public function test_only_registrar_publishes_and_published_candidates_remain_immutable(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $systemSuperAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $term = Term::factory()->create();
        $source = $this->source($term);
        $run = $this->scheduleRun($term);
        $candidate = $this->candidate($run, $source['demand']);

        $this->assertTrue(Gate::forUser($registrar)->allows('publish', $run));
        $this->assertFalse(Gate::forUser($academicHead)->allows('publish', $run));
        $this->assertFalse(Gate::forUser($systemSuperAdmin)->allows('publish', $run));

        foreach ([$academicHead, $systemSuperAdmin] as $unauthorizedActor) {
            try {
                $this->publisher->publish($run, $unauthorizedActor);
                $this->fail('Unauthorized publication was not blocked.');
            } catch (AuthorizationException) {
                $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->fresh()->status);
            }
        }

        $this->publisher->publish($run, $registrar);

        try {
            app(CandidateScheduleRowReviewService::class)->revise($candidate, [
                'faculty_user_id' => $this->faculty->id,
                'room_id' => $this->room->id,
                'day_of_week' => 2,
                'starts_at' => '10:00:00',
                'ends_at' => '12:00:00',
                'override_authority' => 'Registrar correction authority',
                'override_reason' => 'Attempted post-publication mutation.',
            ], $registrar);
            $this->fail('A published candidate row was mutable.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Only the current non-stale candidate can be adjusted.',
                $exception->errors()['candidate'][0] ?? null,
            );
        }

        $candidate->refresh();
        $this->assertSame(1, $run->candidateRows()->count());
        $this->assertSame(1, $candidate->day_of_week);
        $this->assertSame('08:00:00', $candidate->starts_at);
    }

    public function test_filament_publish_modal_renders_impact_and_binding_blocker(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $term = Term::factory()->create();
        $source = $this->source($term);
        $priorRun = $this->scheduleRun($term, ScheduleGenerationRun::StatusPublished, [
            'published_by' => $registrar->id,
            'published_at' => now()->subDay(),
            'publication_version' => 1,
        ]);
        $priorMeeting = $this->officialMeeting($priorRun, $source['demand'], '08:00:00', '10:00:00');
        $newRun = $this->scheduleRun($term);
        $this->candidate($newRun, $source['demand'], startsAt: '10:00:00', endsAt: '12:00:00');
        $this->activeBinding($term, $source['offering'], $priorMeeting);

        Livewire::actingAs($registrar)
            ->test(ViewScheduleGenerationRun::class, ['record' => $newRun->getRouteKey()])
            ->assertActionVisible('publishSchedule')
            ->mountAction('publishSchedule')
            ->assertMountedActionModalSee('1 changed assignment')
            ->assertMountedActionModalSee('1 active student binding')
            ->assertMountedActionModalSee('Full replacement is blocked');
    }

    private function assertPublicationNoteRequired(
        ScheduleGenerationRun $run,
        User $registrar,
        bool $acceptLowerQuality = false,
    ): void {
        try {
            $this->publisher->publish($run, $registrar, null, $acceptLowerQuality);
            $this->fail('Publication without its required reason was not blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('publication_note', $exception->errors());
            $this->assertSame(ScheduleGenerationRun::StatusUnderReview, $run->fresh()->status);
        }
    }

    /**
     * @return array{offering:TermOffering,demand:SchedulingDemand}
     */
    private function source(Term $term): array
    {
        $offering = TermOffering::factory()->for($term)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);
        $specification = CourseSpecification::factory()->create();
        $component = CourseComponent::factory()->for($specification)->create([
            'weekly_contact_hours' => 2.00,
        ]);
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $group = SectionDeliveryGroup::factory()->for($section)->create([
            'modality' => TermOffering::ModalityFaceToFace,
        ]);

        FacultyQualification::factory()
            ->for($this->faculty, 'faculty')
            ->for($specification->course)
            ->create();

        return [
            'offering' => $offering,
            'demand' => SchedulingDemand::factory()
                ->for($offering)
                ->for($component)
                ->for($group)
                ->create([
                    'modality' => TermOffering::ModalityFaceToFace,
                    'meeting_count' => 1,
                    'required_duration_minutes' => 120,
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function scheduleRun(
        Term $term,
        string $status = ScheduleGenerationRun::StatusUnderReview,
        array $overrides = [],
    ): ScheduleGenerationRun {
        return ScheduleGenerationRun::query()->create([
            'term_id' => $term->id,
            'status' => $status,
            'requested_by' => null,
            'input_snapshot' => [],
            'input_hash' => hash('sha256', (string) Str::uuid()),
            'solver_version' => 'tal94d1-test-solver',
            ...$overrides,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     */
    private function candidate(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        string $status = CandidateScheduleRow::StatusOk,
        array $warnings = [],
        ?User $faculty = null,
        string $startsAt = '08:00:00',
        string $endsAt = '10:00:00',
    ): CandidateScheduleRow {
        return CandidateScheduleRow::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => ($faculty ?? $this->faculty)->id,
            'room_id' => $this->room->id,
            'day_of_week' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'time_block_key' => 'D1-'.str_replace(':', '', mb_substr($startsAt, 0, 5)),
            'status' => $status,
            'scores' => [],
            'warnings' => $warnings,
            'violations' => [],
        ]);
    }

    private function officialMeeting(
        ScheduleGenerationRun $run,
        SchedulingDemand $demand,
        string $startsAt,
        string $endsAt,
    ): SectionMeeting {
        return SectionMeeting::query()->create([
            'schedule_run_id' => $run->id,
            'scheduling_demand_id' => $demand->id,
            'meeting_sequence' => 1,
            'faculty_user_id' => $this->faculty->id,
            'room_id' => $this->room->id,
            'day_of_week' => 1,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'modality' => TermOffering::ModalityFaceToFace,
            'state' => SectionMeeting::StateActive,
            'published_at' => now()->subDay(),
        ]);
    }

    private function activeBinding(
        Term $term,
        TermOffering $offering,
        SectionMeeting $meeting,
    ): StudentScheduleBinding {
        $studentProfile = StudentProfile::factory()->create();
        $enrollment = Enrollment::factory()
            ->for($studentProfile)
            ->for($term)
            ->create(['status' => 'pending_payment']);
        $courseEnrollment = CourseEnrollment::query()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ]);

        return StudentScheduleBinding::query()->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'section_meeting_id' => $meeting->id,
            'is_active' => true,
            'effective_from' => now()->toDateString(),
            'source' => StudentScheduleBinding::SourceRegistrarPlacement,
        ]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }
}
