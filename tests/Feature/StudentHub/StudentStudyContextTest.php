<?php

namespace Tests\Feature\StudentHub;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Completion\CompletionReadinessProjection;
use App\Actions\StudentHub\StudentStudyContext;
use App\Filament\Student\Pages\Academics;
use App\Models\CompletionReadinessVersion;
use App\Models\CorVersion;
use App\Models\CourseEnrollment;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\GraduationApplication;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentStudyContextTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 18)->startOfDay());

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());
    }

    public function test_current_official_program_semester_school_year_and_single_subject_level_are_available(): void
    {
        $profile = StudentProfile::factory()->create();
        $profile->program->update(['name' => 'Bachelor of Science in Information Technology']);
        $entryTerm = Term::factory()->create(['state' => Term::StateClosed]);
        $profile->update(['entry_term_id' => $entryTerm->id]);
        $enrollment = $this->officialEnrollment($profile);
        $this->courseAtLevel($enrollment, '2');

        $context = app(StudentStudyContext::class)->forStudent($profile->user);

        $this->assertSame([
            'program_name' => 'Bachelor of Science in Information Technology',
            'curriculum_levels' => ['2'],
            'study_level_label' => 'Year 2',
            'semester' => 'Second Semester',
            'academic_year' => $enrollment->term->academicYear->label,
            'term_label' => 'Current official term',
            'enrollment_id' => $enrollment->id,
            'is_official' => true,
        ], $context);
    }

    public function test_mixed_subject_levels_exclude_dropped_superseded_historical_and_unconfirmed_membership(): void
    {
        $profile = StudentProfile::factory()->create();
        $enrollment = $this->officialEnrollment($profile);
        $this->courseAtLevel($enrollment, '1');
        $this->courseAtLevel($enrollment, '2');
        $this->courseAtLevel($enrollment, '3', ['status' => CourseEnrollment::StatusDropped]);
        $this->courseAtLevel($enrollment, '4', ['is_current' => false]);
        $historical = $this->officialEnrollment($profile, ['state' => Term::StateClosed, 'starts_on' => '2100-01-01', 'ends_on' => '2100-05-31']);
        $this->courseAtLevel($historical, '5');
        $unconfirmed = Enrollment::factory()->for($profile)->for(Term::factory()->create([
            'state' => Term::StateActive,
            'starts_on' => '2101-01-01',
            'ends_on' => '2101-05-31',
        ]))->create();
        $this->courseAtLevel($unconfirmed, '6');

        $context = app(StudentStudyContext::class)->forStudent($profile->user);

        $this->assertSame($enrollment->id, $context['enrollment_id']);
        $this->assertSame(['1', '2'], $context['curriculum_levels']);
        $this->assertSame('Mixed levels (Year 1, Year 2)', $context['study_level_label']);
    }

    public function test_named_subject_level_keeps_its_recorded_case(): void
    {
        $profile = StudentProfile::factory()->create();
        $enrollment = $this->officialEnrollment($profile);
        $this->courseAtLevel($enrollment, 'First Year');

        $context = app(StudentStudyContext::class)->forStudent($profile->user);

        $this->assertSame(['First Year'], $context['curriculum_levels']);
        $this->assertSame('First Year', $context['study_level_label']);
    }

    public function test_historical_and_unconfirmed_enrollment_leave_current_facts_unavailable_and_keep_profile_program(): void
    {
        $profile = StudentProfile::factory()->create();
        $historical = $this->officialEnrollment($profile, ['state' => Term::StateClosed]);
        $this->courseAtLevel($historical, '2');
        Enrollment::factory()->for($profile)->for(Term::factory()->create(['state' => Term::StateActive]))->create();

        $context = app(StudentStudyContext::class)->forStudent($profile->user);

        $this->assertSame([
            'program_name' => $profile->program->name,
            'curriculum_levels' => [],
            'study_level_label' => 'Unavailable',
            'semester' => null,
            'academic_year' => null,
            'term_label' => null,
            'enrollment_id' => null,
            'is_official' => false,
        ], $context);

        $this->signInStudent($profile);
        Livewire::test(Academics::class)->assertSee('Your current subjects appear after official enrollment.')
            ->assertSee('View enrollment')->assertDontSee('Exam dates')->assertDontSee('Year 2');
    }

    public function test_missing_profile_keeps_current_facts_unavailable(): void
    {
        $context = app(StudentStudyContext::class)->forStudent(User::factory()->create());

        $this->assertNull($context['program_name']);
        $this->assertSame([], $context['curriculum_levels']);
        $this->assertSame('Unavailable', $context['study_level_label']);
        $this->assertNull($context['semester']);
        $this->assertNull($context['academic_year']);
        $this->assertFalse($context['is_official']);
    }

    public function test_official_enrollment_without_current_subjects_keeps_subject_level_unavailable(): void
    {
        $profile = StudentProfile::factory()->create();
        $this->officialEnrollment($profile);

        $context = app(StudentStudyContext::class)->forStudent($profile->user);

        $this->assertTrue($context['is_official']);
        $this->assertSame([], $context['curriculum_levels']);
        $this->assertSame('Unavailable', $context['study_level_label']);
    }

    public function test_academics_renders_released_correction_current_average_context_and_history_without_inflating_current_load(): void
    {
        $profile = StudentProfile::factory()->create();
        $profile->program->update(['name' => 'Information Technology']);
        $enrollment = $this->officialEnrollment($profile);
        $current = $this->courseAtLevel($enrollment, '2', ['units_snapshot' => '3.50']);
        $current->termOffering->curriculumEntry->courseSpecification->update(['title' => 'Current corrected subject', 'academic_classification' => CourseSpecification::AcademicClassificationOrdinary]);
        $released = $this->releasedResult($current, '2.25', GradeOutcomeEvent::TypePostedCorrection);
        GradeOutcomeEvent::factory()->for($released->row, 'row')->create([
            'event_type' => GradeOutcomeEvent::TypePendingReplacement,
            'result_code' => '1.00',
            'released_at' => null,
            'predecessor_event_id' => $released->id,
        ]);
        $dropped = $this->courseAtLevel($enrollment, '3', ['status' => CourseEnrollment::StatusDropped]);
        $this->releasedResult($dropped, 'DRP');
        $superseded = $this->courseAtLevel($enrollment, '4', ['is_current' => false]);
        $this->releasedResult($superseded, 'DRP');
        $historical = $this->officialEnrollment($profile, ['state' => Term::StateClosed, 'label' => 'Earlier school term']);
        $oldCourse = $this->courseAtLevel($historical, '1');
        $oldCourse->termOffering->curriculumEntry->courseSpecification->update(['title' => 'Historical completed subject']);
        $this->releasedResult($oldCourse, '1.75', GradeOutcomeEvent::TypeIncResolution);
        $this->signInStudent($profile);

        Livewire::test(Academics::class)
            ->assertSee('Current subjects')
            ->assertSee('subjects')
            ->assertSee('Current corrected subject')
            ->assertSee('Corrected result')
            ->assertSee('2.25')
            ->assertSee('Term weighted average')
            ->assertSee('Information Technology')
            ->assertSee('Year 2')
            ->assertSee('Second Semester')
            ->assertSee($enrollment->term->academicYear->label)
            ->assertViewHas('currentResults', fn ($results): bool => $results->pluck('course_enrollment.id')->all() === [$current->id]
                && $results->sole()['event']->id === $released->id)
            ->assertViewHas('currentUnits', 3.5)
            ->assertViewHas('currentAverage', fn (array $average): bool => $average['value'] === '2.25')
            ->assertViewHas('historicalResults', fn ($results): bool => $results->pluck('course_enrollment.id')->all() === [$dropped->id, $superseded->id, $oldCourse->id])
            ->set('academicView', 'record')
            ->assertSee('Historical completed subject')
            ->assertSee('Earlier school term')
            ->assertSee('INC completed');
    }

    #[TestWith([3])]
    #[TestWith([5])]
    public function test_students_cannot_start_a_graduation_application_for_any_program_duration(int $duration): void
    {
        $profile = StudentProfile::factory()->create();
        $profile->program->update(['duration_years' => $duration]);
        $enrollment = $this->officialEnrollment($profile);
        $this->courseAtLevel($enrollment, 'First Year');
        $this->signInStudent($profile);
        $applicationCount = GraduationApplication::query()->count();

        Livewire::test(Academics::class)
            ->assertDontSee('Apply for graduation')
            ->assertActionDoesNotExist('applyForGraduation')
            ->assertActionDoesNotExist('withdrawGraduationApplication')
            ->mountAction('applyForGraduation')
            ->assertActionNotMounted('applyForGraduation');

        Livewire::withQueryParams(['view' => 'record'])->test(Academics::class)
            ->assertActionHidden('graduation');

        $this->get(Academics::getUrl(['view' => 'graduation'], panel: 'student'))->assertNotFound();
        $this->assertDatabaseCount('graduation_applications', $applicationCount);
    }

    public function test_recorded_graduation_history_is_owned_and_read_only(): void
    {
        $profile = StudentProfile::factory()->create();
        $enrollment = $this->officialEnrollment($profile);
        GraduationApplication::factory()->for($profile)->create([
            'curriculum_version_id' => $profile->curriculum_version_id,
            'term_id' => $enrollment->term_id,
            'state' => GraduationApplication::StateWithdrawn,
            'applied_at' => '2026-06-09 08:00:00',
        ]);
        GraduationApplication::factory()->create(['applied_at' => '2025-03-02 08:00:00']);
        $this->signInStudent($profile);

        $history = Livewire::withQueryParams(['view' => 'graduation'])->test(Academics::class)
            ->assertSee('Application history')->assertSee('Jun 9, 2026')->assertSee('Withdrawn')
            ->assertDontSee('Mar 2, 2025')->assertDontSee('Apply for graduation')
            ->assertActionDoesNotExist('applyForGraduation')->assertActionDoesNotExist('withdrawGraduationApplication');
        $history->assertViewHas('graduationApplications', fn ($applications): bool => $applications->count() === 1
            && $applications->sole()->student_profile_id === $profile->id);
    }

    public function test_current_degree_is_shown_once_and_earlier_degree_records_remain_available(): void
    {
        $profile = StudentProfile::factory()->create();
        $enrollment = $this->officialEnrollment($profile);
        $application = GraduationApplication::factory()->for($profile)->create([
            'curriculum_version_id' => $profile->curriculum_version_id, 'term_id' => $enrollment->term_id,
        ]);
        $readiness = CompletionReadinessVersion::factory()->for($profile)->create(['state' => CompletionReadinessProjection::ReadyForConferral]);
        $conferralSources = [
            'graduation_application_id' => $application->id, 'completion_readiness_version_id' => $readiness->id,
            'curriculum_version_id' => $profile->curriculum_version_id,
        ];
        DegreeConferral::factory()->for($profile)->create([
            ...$conferralSources, 'active_scope_key' => 'student-'.$profile->id, 'version' => 2, 'degree_name' => 'SYNTHETIC CURRENT DEGREE',
        ]);
        DegreeConferral::factory()->for($profile)->create([
            ...$conferralSources, 'degree_name' => 'SYNTHETIC EARLIER DEGREE', 'conferred_on' => now()->subYear(),
        ]);
        $this->signInStudent($profile);

        $page = Livewire::withQueryParams(['view' => 'graduation'])->test(Academics::class);

        $page->assertSee('Earlier degree records')->assertSee('SYNTHETIC EARLIER DEGREE');
        $this->assertSame(1, substr_count(strip_tags($page->html()), 'SYNTHETIC CURRENT DEGREE'));
    }

    public function test_study_plan_names_registrar_recovery_and_current_recorded_requirements(): void
    {
        $profile = StudentProfile::factory()->create();
        $enrollment = $this->officialEnrollment($profile);
        $failed = $this->courseAtLevel($enrollment, '1');
        $this->releasedResult($failed, '5.00');
        $next = CurriculumEntry::factory()->for($profile->curriculumVersion)->create(['year_level' => '2']);
        CourseRequirement::factory()->for($next->courseSpecification)->create([
            'related_course_id' => $failed->termOffering->curriculumEntry->courseSpecification->course_id,
        ]);
        $future = CourseRequirement::factory()->for($next->courseSpecification)->create(['effective_from' => today()->addYear()]);
        $this->signInStudent($profile);

        Livewire::test(Academics::class)
            ->assertSeeHtml('href="'.\App\Filament\Student\Pages\Enrollment::getUrl(panel: 'student').'"')
            ->assertSee('Contact the Registrar about your next enrollment.')
            ->set('academicView', 'plan')
            ->assertSee('Registrar advising needed')
            ->assertSee('Prerequisite: '.$failed->termOffering->curriculumEntry->courseSpecification->course->code)
            ->assertDontSee($future->relatedCourse->code)
            ->assertSee('Ask the Registrar about completing or retaking this subject.');

        $retakeEnrollment = $this->officialEnrollment($profile);
        $retakeOffering = TermOffering::factory()->for($retakeEnrollment->term)->for($failed->termOffering->curriculumEntry, 'curriculumEntry')->create();
        CourseEnrollment::factory()->for($retakeEnrollment)->for($retakeOffering, 'termOffering')->create([
            'is_current' => true,
            'status' => CourseEnrollment::StatusActive,
        ]);

        $evaluation = app(CurriculumEvaluation::class)->forStudent($profile->fresh());
        $this->assertSame('In progress', collect($evaluation['required'])->firstWhere('curriculum_entry.id', $failed->termOffering->curriculum_entry_id)['status']);
    }

    private function signInStudent(StudentProfile $profile): void
    {
        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $profile->user->assignRole('student');
        Filament::setCurrentPanel(Filament::getPanel('student'));
        $this->actingAs($profile->user);
    }

    private function releasedResult(CourseEnrollment $course, string $result, string $type = GradeOutcomeEvent::TypeInitialRelease): GradeOutcomeEvent
    {
        $roster = GradeRoster::factory()->for($course->termOffering, 'termOffering')->for($course->section)->create();
        $row = GradeRosterRow::factory()->for($roster, 'roster')->for($course, 'courseEnrollment')->create();

        return GradeOutcomeEvent::factory()->for($row, 'row')->create([
            'event_type' => $type,
            'result_code' => $result,
            'new_value' => is_numeric($result) ? $result : null,
            'released_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $termAttributes */
    private function officialEnrollment(StudentProfile $profile, array $termAttributes = []): Enrollment
    {
        $term = Term::factory()->create(array_replace([
            'type' => Term::TypeSecondSemester,
            'label' => 'Current official term',
            'state' => Term::StateActive,
        ], $termAttributes));
        $enrollment = Enrollment::factory()->for($profile)->for($term)->create([
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $cor = CorVersion::factory()->for($enrollment)->create();
        $enrollment->update(['current_cor_version_id' => $cor->id]);

        return $enrollment;
    }

    /** @param array<string, mixed> $attributes */
    private function courseAtLevel(Enrollment $enrollment, string $level, array $attributes = []): CourseEnrollment
    {
        $entry = CurriculumEntry::factory()->for($enrollment->studentProfile->curriculumVersion)->create(['year_level' => $level]);
        $offering = TermOffering::factory()->for($enrollment->term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $timetable = PublishedTimetableVersion::query()->where('term_id', $enrollment->term_id)->first()
            ?? PublishedTimetableVersion::factory()->for($enrollment->term)->create();

        return CourseEnrollment::factory()->for($enrollment)->for($offering, 'termOffering')->for($section)
            ->for($timetable, 'publishedTimetableVersion')->create($attributes);
    }
}
