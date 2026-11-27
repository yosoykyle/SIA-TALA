<?php

namespace Tests\Feature\StudentHub;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Filament\Student\Pages\Academics;
use App\Filament\Student\Pages\Profile;
use App\Models\AcademicDecision;
use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue111GradesAndNavigationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame((string) env('TALA_TEST_DATABASE', 'test_tala_db'), DB::connection()->getDatabaseName());

        Role::query()->firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('student'));
    }

    #[Test]
    public function sidebar_promotes_grades_with_study_plan_child_and_retires_current_subjects(): void
    {
        [$user] = $this->studentWithProfile();
        $this->actingAs($user);

        $response = $this->get(Academics::getUrl(panel: 'student'))
            ->assertOk()
            ->assertSee('Grades')
            ->assertSee('Study plan')
            ->assertDontSee('Current subjects')
            ->assertDontSee('Academic record');

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        $sidebarText = trim((string) $xpath->query('//*[@id="fi-main-sidebar"]')->item(0)?->textContent);
        $this->assertStringContainsString('Grades', $sidebarText);
        $this->assertStringContainsString('Study plan', $sidebarText);
        $this->assertStringNotContainsString('Current subjects', $sidebarText);
        $this->assertStringNotContainsString('Academic record', $sidebarText);

        // Legacy ?view=current bookmarks safely resolve to Grades without 404.
        Livewire::actingAs($user)
            ->withQueryParams(['view' => 'current'])
            ->test(Academics::class)
            ->assertOk()
            ->assertSet('academicView', 'record');
    }

    #[Test]
    public function grades_page_computes_unit_weighted_term_gpa_academic_year_gwa_and_cumulative_gwa_without_clutter(): void
    {
        [$user, $profile] = $this->studentWithProfile();

        $ayCompleted = AcademicYear::factory()->create([
            'label' => 'AY 2091-2092',
            'starts_on' => '2091-08-01',
            'ends_on' => '2092-05-31',
        ]);
        $term1 = Term::factory()->for($ayCompleted)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2091-08-01',
            'ends_on' => '2091-12-15',
            'state' => Term::StateClosed,
        ]);
        $term2 = Term::factory()->for($ayCompleted)->create([
            'type' => Term::TypeSecondSemester,
            'label' => 'Second Semester',
            'starts_on' => '2092-01-10',
            'ends_on' => '2092-05-31',
            'state' => Term::StateClosed,
        ]);

        // Term 1: 3u @ 1.50 (4.50) + 3u @ 2.00 (6.00) = 10.50 / 6 = 1.75
        $this->recordGradedSubject($profile, $term1, 'IT-101', 'Computing Fundamentals', '3.00', '1.50');
        $this->recordGradedSubject($profile, $term1, 'IT-102', 'Programming Logic', '3.00', '2.00');

        // Term 2: 5u @ 1.00 (5.00) + 4u @ 2.50 (10.00) = 15.00 / 9 = 1.67
        // Arithmetic mean of term GPAs would be (1.75 + 1.67) / 2 = 1.71 (WRONG).
        // True PH unit-weighted AY GWA = (4.50 + 6.00 + 5.00 + 10.00) / (3 + 3 + 5 + 4) = 25.50 / 15 = 1.70.
        $this->recordGradedSubject($profile, $term2, 'IT-103', 'Data Structures', '5.00', '1.00');
        $this->recordGradedSubject($profile, $term2, 'IT-104', 'Discrete Mathematics', '4.00', '2.50');

        // Second Academic Year with an unreleased subject -> AY GWA must show Pending.
        $ayOngoing = AcademicYear::factory()->create([
            'label' => 'AY 2092-2093',
            'starts_on' => '2092-08-01',
            'ends_on' => '2093-05-31',
        ]);
        $term3 = Term::factory()->for($ayOngoing)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'starts_on' => '2092-08-01',
            'ends_on' => '2092-12-15',
            'state' => Term::StateActive,
        ]);
        $this->recordGradedSubject($profile, $term3, 'IT-201', 'Database Systems', '3.00', null);

        $component = Livewire::actingAs($user)
            ->test(Academics::class)
            ->assertOk()
            ->assertSet('academicView', 'record')
            ->assertActionDoesNotExist('unofficialRecord')
            ->assertSee('Cumulative GWA')
            ->assertSee('Academic Year GWA')
            ->assertSee('Term GPA')
            ->assertSee('AY 2091-2092')
            ->assertSee('AY 2092-2093')
            ->assertSee('1.75')
            ->assertSee('1.67')
            ->assertSee('1.70')
            ->assertSee('GWA: Pending')
            ->assertDontSee('1.71')
            ->assertDontSee('Term weighted average')
            ->assertDontSee('Unofficial record')
            ->assertDontSee('Released results')
            ->assertDontSee('Average sources')
            ->assertDontSee('School record changes');

        $html = $component->html();
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);

        $headerCells = [];
        foreach ($xpath->query('(//table[contains(@class,"student-academic-table")])[1]//thead//th') as $th) {
            $headerCells[] = trim($th->textContent);
        }
        $this->assertSame(['Subject', 'Units', 'Grades'], $headerCells);
        $this->assertNotContains('Remarks', $headerCells);

        $component->assertViewHas('recordAcademicYears', function ($years): bool {
            $completed = $years->firstWhere('label', 'AY 2091-2092');
            $ongoing = $years->firstWhere('label', 'AY 2092-2093');

            return $completed !== null
                && $completed['gwa']['value'] === '1.70'
                && $ongoing !== null
                && $ongoing['gwa']['value'] === null;
        });
    }

    #[Test]
    public function academic_guidance_banner_renders_at_top_of_grades_and_study_plan_without_review_enrollment_button(): void
    {
        [$user, $profile] = $this->studentWithProfile();
        CurriculumEntry::factory()->for($profile->curriculumVersion)->create(['year_level' => '1']);

        AcademicDecision::query()->create([
            'student_profile_id' => $profile->id,
            'term_id' => null,
            'effect' => AcademicDecision::EffectBlocked,
            'state' => 'ACTIVE',
            'reason' => 'Registrar advising is required before your next enrollment.',
            'effective_from' => now()->subDay()->toDateString(),
            'recorded_by' => $user->id,
            'recorded_at' => now(),
        ]);

        // Grades view: banner at top, no redundant Review enrollment button.
        $grades = Livewire::actingAs($user)
            ->test(Academics::class)
            ->assertSee('Registrar advising is required before your next enrollment.')
            ->assertDontSee('Review enrollment');

        $gradesHtml = $grades->html();
        $this->assertLessThan(
            strpos($gradesHtml, 'No released results yet'),
            strpos($gradesHtml, 'Registrar advising is required before your next enrollment.'),
        );

        // Study plan view: banner at top (before program overview card), no redundant Review enrollment button.
        $plan = Livewire::actingAs($user)
            ->withQueryParams(['view' => 'plan'])
            ->test(Academics::class)
            ->assertSee('Registrar advising is required before your next enrollment.')
            ->assertDontSee('Review enrollment');

        $planHtml = $plan->html();
        $this->assertLessThan(
            strpos($planHtml, 'student-plan-overview'),
            strpos($planHtml, 'Registrar advising is required before your next enrollment.'),
        );
    }

    #[Test]
    public function school_record_changes_move_to_profile_only_when_changes_exist(): void
    {
        Mail::fake();
        [$user, $profile] = $this->studentWithProfile();

        // Without applied lifecycle changes, Profile stays spotless (no empty School record changes block).
        Livewire::actingAs($user)
            ->test(Profile::class)
            ->assertOk()
            ->assertDontSee('School record changes');

        // Once applied, notification points to Profile#school-record-changes and Profile renders the section.
        $change = StudentLifecycleChange::factory()->create([
            'student_profile_id' => $profile->id,
            'type' => StudentLifecycleChange::TypeProgramShift,
            'state' => StudentLifecycleChange::StateApplied,
            'effective_on' => now()->toDateString(),
        ]);

        $event = app(AcademicRecordNotificationService::class)->recordLifecycleAfterCommit($change);
        $this->assertSame(
            Profile::getUrl(['change' => $change->id], isAbsolute: false, panel: 'student').'#school-record-changes',
            $event?->payload['action_path'],
        );

        Livewire::actingAs($user)
            ->withQueryParams(['change' => $change->id])
            ->test(Profile::class)
            ->assertOk()
            ->assertSee('School record changes')
            ->assertSee('Program change');

        // Grades page stays clean of School record changes.
        Livewire::actingAs($user)
            ->test(Academics::class)
            ->assertDontSee('School record changes');
    }

    /** @return array{User, StudentProfile} */
    private function studentWithProfile(): array
    {
        $user = User::factory()->create([
            'status' => User::StatusActive,
            'email_verified_at' => now(),
        ]);
        $user->assignRole('student');

        $profile = StudentProfile::factory()->create([
            'user_id' => $user->id,
            'lifecycle_status' => StudentProfile::LifecycleActive,
        ]);

        return [$user, $profile];
    }

    private function recordGradedSubject(
        StudentProfile $profile,
        Term $term,
        string $code,
        string $title,
        string $units,
        ?string $grade,
    ): void {
        $course = Course::factory()->create(['code' => $code]);
        $spec = CourseSpecification::factory()->for($course)->create([
            'title' => $title,
            'credit_units' => (float) $units,
            'academic_classification' => CourseSpecification::AcademicClassificationOrdinary,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry = CurriculumEntry::factory()->for($profile->curriculumVersion)->for($spec, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => $term->type,
            'term_label' => $term->label,
        ]);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $timetable = PublishedTimetableVersion::query()->where('term_id', $term->id)->first()
            ?? PublishedTimetableVersion::factory()->for($term)->create();
        $enrollment = Enrollment::query()
            ->where('student_profile_id', $profile->id)
            ->where('term_id', $term->id)
            ->first()
            ?? Enrollment::factory()->for($profile)->for($term)->create([
                'status' => 'officially_enrolled',
                'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
                'officially_enrolled_at' => now(),
            ]);

        $courseEnrollment = CourseEnrollment::factory()
            ->for($enrollment)
            ->for($offering, 'termOffering')
            ->for($section)
            ->for($timetable, 'publishedTimetableVersion')
            ->create([
                'status' => CourseEnrollment::StatusActive,
                'is_current' => true,
                'units_snapshot' => $units,
            ]);

        if ($grade === null) {
            return;
        }

        $roster = GradeRoster::factory()->for($offering, 'termOffering')->for($section)->create();
        $row = GradeRosterRow::factory()->for($roster, 'roster')->for($courseEnrollment, 'courseEnrollment')->create([
            'released_at' => now(),
            'current_outcome_code' => $grade,
            'current_outcome_category' => GradeRosterRow::CategoryPassing,
        ]);
        GradeOutcomeEvent::factory()->for($row, 'row')->create([
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => $grade,
            'new_value' => $grade,
            'released_at' => now(),
        ]);
    }
}
