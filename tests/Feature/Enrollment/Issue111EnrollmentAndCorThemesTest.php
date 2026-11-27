<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Enrollment\RegistrationEntryContext;
use App\Filament\Student\Pages\CorView;
use App\Filament\Student\Pages\Enrollment as StudentEnrollmentPage;
use App\Models\AcademicDecision;
use App\Models\CorVersion;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Hold;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCalendarWindow;
use App\Models\TermOffering;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue111EnrollmentAndCorThemesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00', 'Asia/Manila'));
        $this->assertSame('testing', app()->environment());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        foreach (['student', User::StaffRoleRegistrar] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Filament::setCurrentPanel(Filament::getPanel('student'));
    }

    #[Test]
    public function regular_student_sees_enroll_now_cta_and_single_pass_entry_context_without_irregular_request_query(): void
    {
        $student = $this->student();
        $term = $this->openTerm('Second Semester 2026-2027');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $context = app(RegistrationEntryContext::class)->forUser($student->user);

        $windowQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'term_calendar_windows'))
            ->count();
        DB::disableQueryLog();

        $this->assertTrue($context['can_start']);
        $this->assertSame('open', $context['context_state']);
        $this->assertSame($term->id, $context['term']?->id);
        $this->assertLessThanOrEqual(3, $windowQueries, 'RegistrationEntryContext should evaluate the open term in a single pass.');

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($student->user)
            ->test(StudentEnrollmentPage::class)
            ->assertSee('Enroll now')
            ->assertDontSee('Prepare enrollment')
            ->assertActionVisible('startRegistration');

        $irregularQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'irregular_enrollment_requests'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(0, $irregularQueries, 'Regular enrollment entry must not query irregular_enrollment_requests.');
    }

    #[Test]
    public function official_course_result_projection_memoizes_within_request_and_invalidates_on_grade_mutation(): void
    {
        $student = $this->student();
        $pastTerm = Term::factory()->create([
            'label' => 'First Semester 2026-2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-10-15',
            'state' => Term::StateClosed,
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($pastTerm)->create([
            'credential_user_id' => $student->user_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now()->subMonth(),
        ]);
        $course = Course::factory()->create(['code' => 'MEM101']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($student->curriculumVersion)->for($spec, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($pastTerm)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $courseEnrollment = CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'section_id' => $section->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);

        $projection = app(OfficialCourseResultProjection::class);
        $first = $projection->forStudent($student);
        $this->assertNull($first->sole()['result']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = $projection->forStudent($student);
        $cachedQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $cachedQueryCount, 'Repeated projection reads within the same request must reuse the in-memory cache.');
        $this->assertSame($first->count(), $second->count());

        $roster = GradeRoster::factory()->for($offering, 'termOffering')->for($section)->create();
        $row = GradeRosterRow::factory()->for($roster, 'roster')->create([
            'course_enrollment_id' => $courseEnrollment->id,
            'current_outcome_code' => '1.75',
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
            'released_at' => now(),
        ]);
        GradeOutcomeEvent::query()->create([
            'grade_roster_row_id' => $row->id,
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => '1.75',
            'new_value' => '1.75',
            'new_category' => GradeRosterRow::CategoryPassing,
            'reason' => 'Initial official release',
            'recorded_by' => $student->user_id,
            'released_at' => now(),
        ]);

        $afterRelease = $projection->forStudent($student);
        $this->assertSame('1.75', $afterRelease->sole()['result']);
    }

    #[Test]
    public function academic_guidance_directs_missing_grades_to_professor_first_and_makes_button_optional(): void
    {
        $student = $this->student();
        $pastTerm = Term::factory()->create([
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-10-15',
            'state' => Term::StateClosed,
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($pastTerm)->create([
            'credential_user_id' => $student->user_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now()->subMonth(),
        ]);
        $course = Course::factory()->create(['code' => 'MISS101']);
        $spec = CourseSpecification::factory()->for($course)->create(['state' => CourseSpecification::StateActive]);
        $entry = CurriculumEntry::factory()->for($student->curriculumVersion)->for($spec, 'courseSpecification')->create();
        $offering = TermOffering::factory()->for($pastTerm)->for($entry, 'curriculumEntry')->create();
        CourseEnrollment::factory()->create([
            'enrollment_id' => $enrollment->id,
            'term_offering_id' => $offering->id,
            'status' => CourseEnrollment::StatusActive,
            'is_current' => true,
        ]);

        $effect = app(AcademicEnrollmentEffect::class)->forStudent($student);

        $this->assertSame(AcademicDecision::EffectAdvisingRequired, $effect['effect']);
        $this->assertStringContainsString('professor or instructor first', $effect['student_reason']);
        $this->assertStringContainsString('dependent subjects stay on hold', $effect['student_reason']);

        $withoutButton = view('filament.student.pages.academic-guidance', [
            'effect' => [
                'label' => 'Advising required',
                'student_reason' => $effect['student_reason'],
                'scope_label' => 'Enrollment · Next Term',
            ],
            'enrollmentUrl' => '/student/enrollment',
        ])->render();

        $this->assertStringContainsString('professor or instructor first', $withoutButton);
        $this->assertStringNotContainsString('Review enrollment', $withoutButton);

        $withButton = view('filament.student.pages.academic-guidance', [
            'effect' => [
                'label' => 'Advising required',
                'student_reason' => $effect['student_reason'],
                'scope_label' => 'Enrollment · Next Term',
            ],
            'enrollmentUrl' => '/student/academics?view=plan',
            'guidanceActionLabel' => 'View study plan',
        ])->render();

        $this->assertStringContainsString('View study plan', $withButton);
    }

    #[Test]
    public function cor_end_of_term_fallback_labels_previous_completed_term_and_still_enforces_holds_and_lifecycle_blocks(): void
    {
        $student = $this->student();
        $program = $student->program;
        $curriculum = CurriculumVersion::factory()->for($program)->create(['name' => 'DIT 2026 Curriculum']);
        $student->update(['curriculum_version_id' => $curriculum->id]);

        $closedTerm = Term::factory()->create([
            'label' => 'Second Semester 2025-2026',
            'state' => Term::StateClosed,
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($closedTerm)->create([
            'credential_user_id' => $student->user_id,
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'status' => 'officially_enrolled',
            'officially_enrolled_at' => now()->subMonths(3),
        ]);
        $corVersion = CorVersion::factory()->for($enrollment)->create([
            'version' => 1,
            'snapshot' => [
                'student_number' => $student->student_number,
                'student_name' => $student->first_name.' '.$student->last_name,
                'program_code' => $program->code,
                'program_name' => $program->name,
                'curriculum_version_id' => $curriculum->id,
                'represented_curriculum_levels' => ['1'],
                'selection_basis' => Enrollment::SelectionStandardCurriculum,
                'term_label' => $closedTerm->label,
                'published_timetable_version_id' => 99,
                'assessment_basis' => 'PUBLISHED_FEE_PLAN',
                'assessment_total' => '5000.00',
                'fees' => [['label' => 'Tuition Fee', 'amount' => '5000.00']],
                'courses' => [[
                    'course_enrollment_id' => 1,
                    'course_code' => 'IT101',
                    'course_title' => 'Intro to Computing',
                    'units' => '3.00',
                    'section_code' => 'A',
                    'contact_hours' => ['lecture' => '3.00', 'laboratory' => '0.00'],
                    'meetings' => [[
                        'day_of_week' => 1,
                        'starts_at' => '08:00:00',
                        'ends_at' => '11:00:00',
                        'room_label' => 'RM-101',
                        'faculty_name' => 'Prof. Santos',
                        'modality' => 'FACE_TO_FACE',
                    ]],
                ]],
                'issued_by_name' => 'Registrar Officer',
                'issued_at' => now()->subMonths(3)->toIso8601String(),
            ],
            'issued_at' => now()->subMonths(3),
        ]);
        $enrollment->update(['current_cor_version_id' => $corVersion->id]);

        Livewire::actingAs($student->user)
            ->test(CorView::class)
            ->assertSet('cor.summary.document_status', 'Previous term')
            ->assertSee('Previous term')
            ->assertSee('Last completed term · '.$closedTerm->label)
            ->assertSee('Section / Modality')
            ->assertSee('Face-to-face')
            ->assertSee('Sec. A')
            ->assertDontSee('Class schedule')
            ->assertDontSee('View fees and payments');

        $printHtml = $this->actingAs($student->user)
            ->get(route('cor.print', $enrollment))
            ->assertOk()
            ->assertSee($student->student_number)
            ->assertSee('DIT 2026 Curriculum')
            ->assertSee('Section / Modality')
            ->assertSee('Face-to-face')
            ->assertSee('Sec. A')
            ->assertSee('Document reference')
            ->assertSee('Issued at')
            ->assertSee('Curriculum')
            ->assertSee('Official Certificate of Registration')
            ->assertDontSee('Timetable source')
            ->assertDontSee('Published timetable version #99')
            ->getContent();

        $this->assertStringContainsString('cor-identity-number', (string) $printHtml);

        $hold = Hold::query()->create([
            'student_profile_id' => $student->id,
            'term_id' => $closedTerm->id,
            'enrollment_id' => $enrollment->id,
            'hold_type' => Hold::TypeCorDownload,
            'blocking_level' => Hold::BlockingCorPrint,
            'status' => Hold::StatusActive,
            'reason' => 'Unsettled balance hold.',
            'student_message' => 'Settle your clearance before downloading your COR.',
            'source_type' => Enrollment::class,
            'source_id' => $enrollment->id,
            'effective_at' => now()->subDay(),
        ]);

        Livewire::actingAs($student->user)
            ->test(CorView::class)
            ->assertSee('No issued COR to show')
            ->assertSee('Settle your clearance before downloading your COR.');

        $hold->update(['status' => Hold::StatusResolved]);

        $student->update(['lifecycle_status' => StudentProfile::LifecycleInactive]);

        Livewire::actingAs($student->user)
            ->test(CorView::class)
            ->assertSee('No issued COR to show')
            ->assertSee('Inactive');
    }

    private function student(): StudentProfile
    {
        $student = StudentProfile::factory()->create();
        $student->user->assignRole('student');

        return $student;
    }

    private function openTerm(string $label): Term
    {
        $term = Term::factory()->create([
            'label' => $label,
            'state' => Term::StateActive,
            'registration_applicability' => 'AllEligible',
        ]);
        $package = TermCalendarPackage::factory()->create([
            'term_id' => $term->id,
            'state' => TermCalendarPackage::StateActive,
        ]);
        TermCalendarWindow::factory()->create([
            'term_calendar_package_id' => $package->id,
            'window_type' => TermCalendarWindow::TypeEnrollment,
            'opens_on' => '2026-11-01',
            'closes_on' => '2026-11-30',
        ]);

        return $term;
    }
}
