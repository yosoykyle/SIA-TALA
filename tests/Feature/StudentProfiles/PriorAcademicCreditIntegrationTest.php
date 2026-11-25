<?php

namespace Tests\Feature\StudentProfiles;

use App\Actions\Academics\AcademicAverageReadiness;
use App\Actions\Academics\CumulativeGwaProjection;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Academics\TermWeightedAverageProjection;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Actions\Completion\TranscriptPreview;
use App\Actions\Enrollment\AcademicProgressionService;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Enrollment\RegistrationSelectionBasisResolver;
use App\Actions\Graduation\GraduationEligibilitySnapshotService;
use App\Actions\StudentProfiles\OnboardExistingStudent;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Student\Pages\Academics;
use App\Models\Course;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationReviewMember;
use App\Models\PriorAcademicCredit;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TranscriptRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PriorAcademicCreditIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_irregular_imported_student_routes_to_individually_advised_selection_basis(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createCurriculumFixture();

        $irregular = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2023-9001',
            'first_name' => 'Irregular',
            'last_name' => 'Student',
            'email' => 'irreg.9001@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'academic_standing' => StudentProfile::StandingIrregular,
            'send_activation_email' => false,
        ], $registrar)['profile'];

        $regular = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2023-9002',
            'first_name' => 'Regular',
            'last_name' => 'Student',
            'email' => 'reg.9002@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'academic_standing' => StudentProfile::StandingRegular,
            'send_activation_email' => false,
        ], $registrar)['profile'];

        $resolver = app(RegistrationSelectionBasisResolver::class);

        $this->assertSame(Enrollment::SelectionIndividuallyAdvised, $resolver->forContinuingStudent($irregular));
        $this->assertSame(Enrollment::SelectionStandardCurriculum, $resolver->forContinuingStudent($regular));
    }

    public function test_prior_academic_credits_drive_gwa_prerequisites_curriculum_progression_graduation_and_all_record_surfaces(): void
    {
        Notification::fake();

        $registrar = $this->staff(User::StaffRoleRegistrar);
        $fixtures = $this->createCurriculumFixture();

        $onboarded = app(OnboardExistingStudent::class)->execute([
            'student_number' => 'SIA-2023-9100',
            'first_name' => 'Patricia',
            'last_name' => 'Villamor',
            'email' => 'patricia.villamor.c3@example.test',
            'program_id' => $fixtures['program']->id,
            'curriculum_version_id' => $fixtures['curriculum']->id,
            'academic_standing' => StudentProfile::StandingRegular,
            'prior_credits' => [
                [
                    'course_specification_id' => $fixtures['spec1']->id,
                    'curriculum_entry_id' => $fixtures['entry1']->id,
                    'academic_year_label' => 'AY 2023-2024',
                    'term_label' => 'First Semester',
                    'year_level' => '1',
                    'final_grade' => '1.50',
                    'result_status' => PriorAcademicCredit::ResultPassed,
                    'source_school' => 'Servitech Institute',
                ],
                [
                    'course_specification_id' => $fixtures['spec2']->id,
                    'curriculum_entry_id' => $fixtures['entry2']->id,
                    'academic_year_label' => 'AY 2023-2024',
                    'term_label' => 'First Semester',
                    'year_level' => '1',
                    'final_grade' => '2.00',
                    'result_status' => PriorAcademicCredit::ResultPassed,
                    'source_school' => 'Servitech Institute',
                ],
                [
                    'course_specification_id' => $fixtures['specCredit']->id,
                    'curriculum_entry_id' => $fixtures['entryCredit']->id,
                    'academic_year_label' => 'AY 2023-2024',
                    'term_label' => 'First Semester',
                    'year_level' => '1',
                    'final_grade' => null,
                    'result_status' => PriorAcademicCredit::ResultCredited,
                    'source_school' => 'Previous College',
                ],
                [
                    'course_specification_id' => $fixtures['specPe']->id,
                    'curriculum_entry_id' => $fixtures['entryPe']->id,
                    'academic_year_label' => 'AY 2023-2024',
                    'term_label' => 'First Semester',
                    'year_level' => '1',
                    'final_grade' => '1.00',
                    'result_status' => PriorAcademicCredit::ResultPassed,
                    'source_school' => 'Servitech Institute',
                ],
            ],
            'send_activation_email' => false,
        ], $registrar);

        $profile = $onboarded['profile'];
        $studentUser = $onboarded['user'];

        // Add a REVOKED credit on coursePrereqTarget to verify revoked rows are strictly ignored.
        PriorAcademicCredit::query()->create([
            'student_profile_id' => $profile->id,
            'course_specification_id' => $fixtures['specTarget']->id,
            'curriculum_entry_id' => $fixtures['entryTarget']->id,
            'academic_year_label' => 'AY 2023-2024',
            'term_label' => 'Second Semester',
            'year_level' => '1',
            'final_grade' => '1.25',
            'result_status' => PriorAcademicCredit::ResultPassed,
            'state' => PriorAcademicCredit::StateRevoked,
            'recorded_by' => $registrar->id,
            'recorded_at' => now(),
            'revoked_by' => $registrar->id,
            'revoked_at' => now(),
        ]);

        // 1. OfficialCourseResultProjection + GWA (numeric SIA grades 1.50 & 2.00 count; CREDIT & PE excluded).
        $results = app(OfficialCourseResultProjection::class)->forStudent($profile);
        $this->assertCount(4, $results);

        $periodAverage = app(TermWeightedAverageProjection::class)->forPeriodResults($results, 'First Semester');
        $this->assertSame(AcademicAverageReadiness::Available, $periodAverage['state']);
        $this->assertSame('1.75', $periodAverage['value']); // (1.50*3 + 2.00*3) / 6 = 1.75

        $cumulative = app(CumulativeGwaProjection::class)->forStudent($profile);
        $this->assertSame(AcademicAverageReadiness::Available, $cumulative['state']);
        $this->assertSame('1.75', $cumulative['value']);
        $this->assertSame(2, $cumulative['included_attempts']);

        // 2. Prerequisites: specTarget requires course1; prior credit for course1 satisfies it, revoked target is NOT satisfied.
        $satisfiedCourseIds = app(RegistrationAcademicEligibilityQuery::class)->satisfiedCourseIds($profile);
        $this->assertTrue($satisfiedCourseIds->contains($fixtures['course1']->id));
        $this->assertTrue($satisfiedCourseIds->contains($fixtures['courseCredit']->id));
        $this->assertFalse($satisfiedCourseIds->contains($fixtures['courseTarget']->id));

        // 3. CurriculumEvaluation counts active prior credits and leaves revoked target remaining.
        $evaluation = app(CurriculumEvaluation::class)->forStudent($profile);
        $this->assertSame('11.00', $evaluation['completed_units']); // 3 + 3 + 3 + 2
        $this->assertSame(1, $evaluation['deficiency_count']);
        $this->assertSame(4, collect($evaluation['required'])->where('status', 'Completed')->count());

        // 4. AcademicProgressionService & GraduationEligibilitySnapshotService include active prior credits.
        $progression = app(AcademicProgressionService::class)->evaluate($profile);
        $this->assertSame(4, $progression['facts']['completed_count']);
        $this->assertSame('1.75', $progression['gwa']);

        $term = Term::factory()->create();
        $batch = GraduationReviewBatch::factory()->create([
            'academic_year_id' => $term->academic_year_id,
            'term_id' => $term->id,
            'created_by' => $registrar->id,
        ]);
        $member = GraduationReviewMember::factory()->create([
            'graduation_review_batch_id' => $batch->id,
            'student_profile_id' => $profile->id,
            'added_by' => $registrar->id,
        ]);
        $snapshot = app(GraduationEligibilitySnapshotService::class)->generate($member, $registrar);
        $this->assertSame(GraduationEligibilitySnapshotService::ResultBlockedMissingRequirement, $snapshot->result_status);
        $this->assertCount(4, $snapshot->evaluation_snapshot['completed_requirements'] ?? []);
        $this->assertCount(1, $snapshot->evaluation_snapshot['missing_requirements'] ?? []);

        // 5. TranscriptPreview renders pre-TALA prior credits cleanly without null course_enrollment crashes.
        $transcriptRequest = TranscriptRequest::factory()->create([
            'student_profile_id' => $profile->id,
            'external_request_reference' => 'TOR-C3-9100',
            'template_version' => TranscriptRequest::TemplateServitechV2,
            'signatory_name' => 'Registrar Signatory',
            'signatory_title' => 'School Registrar',
            'state' => TranscriptRequest::StateOpen,
            'recorded_by' => $registrar->id,
        ]);
        $transcript = app(TranscriptPreview::class)->forRequest($transcriptRequest);
        $allTorRows = collect($transcript['academic_years'])->flatten(1);
        $this->assertCount(4, $allTorRows);
        $this->assertTrue($allTorRows->contains(fn (array $row): bool => ($row['course_code'] ?? null) === $fixtures['course1']->code
            && ($row['result'] ?? null) === '1.50'));

        // 6. Registrar ViewStudentProfile (Academic tab) renders pre-TALA period & grades cleanly.
        Livewire::test(ViewStudentProfile::class, ['record' => $profile->getRouteKey()])
            ->call('selectRecordTab', 'academic')
            ->assertSee('AY 2023-2024')
            ->assertSee($fixtures['course1']->code)
            ->assertSee('1.50');

        // 7. Student Hub Academics (record view) and Unofficial Student Record render pre-TALA grades cleanly.
        $studentUser->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($studentUser)->withSession([WorkspaceContextResolver::SessionKey => 'student']);
        Filament::setCurrentPanel(Filament::getPanel('student'));

        Livewire::test(Academics::class)
            ->set('academicView', 'record')
            ->assertSee('AY 2023-2024')
            ->assertSee($fixtures['course1']->code)
            ->assertSee('1.50');

        $this->get(route('student-academics.unofficial-record', $profile))
            ->assertOk()
            ->assertSee('AY 2023-2024')
            ->assertSee($fixtures['course1']->code)
            ->assertSee('1.50');
    }

    /**
     * @return array{
     *     program: Program,
     *     curriculum: CurriculumVersion,
     *     course1: Course,
     *     course2: Course,
     *     courseCredit: Course,
     *     coursePe: Course,
     *     courseTarget: Course,
     *     spec1: CourseSpecification,
     *     spec2: CourseSpecification,
     *     specCredit: CourseSpecification,
     *     specPe: CourseSpecification,
     *     specTarget: CourseSpecification,
     *     entry1: CurriculumEntry,
     *     entry2: CurriculumEntry,
     *     entryCredit: CurriculumEntry,
     *     entryPe: CurriculumEntry,
     *     entryTarget: CurriculumEntry
     * }
     */
    private function createCurriculumFixture(): array
    {
        $program = Program::factory()->create([
            'code' => 'DIT-INT',
            'name' => 'Diploma in Information Technology (Integration)',
            'duration_years' => 3,
            'is_active' => true,
        ]);

        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'DIT-INT-2023',
            'state' => CurriculumVersion::StateActive,
        ]);

        $course1 = Course::factory()->create(['code' => 'INTIT 101']);
        $spec1 = CourseSpecification::factory()->for($course1)->create([
            'title' => 'Intro to Computing',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry1 = CurriculumEntry::factory()->for($curriculum)->for($spec1, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 1,
        ]);

        $course2 = Course::factory()->create(['code' => 'INTGE 101']);
        $spec2 = CourseSpecification::factory()->for($course2)->create([
            'title' => 'Mathematics in the Modern World',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entry2 = CurriculumEntry::factory()->for($curriculum)->for($spec2, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 2,
        ]);

        $courseCredit = Course::factory()->create(['code' => 'INTGE 102']);
        $specCredit = CourseSpecification::factory()->for($courseCredit)->create([
            'title' => 'Readings in Philippine History',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entryCredit = CurriculumEntry::factory()->for($curriculum)->for($specCredit, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 3,
        ]);

        $coursePe = Course::factory()->create(['code' => 'PE 1']);
        $specPe = CourseSpecification::factory()->for($coursePe)->create([
            'title' => 'Physical Education 1',
            'credit_units' => 2,
            'academic_classification' => CourseSpecification::AcademicClassificationPe,
            'state' => CourseSpecification::StateActive,
        ]);
        $entryPe = CurriculumEntry::factory()->for($curriculum)->for($specPe, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'term_label' => 'First Semester',
            'sequence' => 4,
        ]);

        $courseTarget = Course::factory()->create(['code' => 'INTIT 102']);
        $specTarget = CourseSpecification::factory()->for($courseTarget)->create([
            'title' => 'Computer Programming 2',
            'credit_units' => 3,
            'state' => CourseSpecification::StateActive,
        ]);
        $entryTarget = CurriculumEntry::factory()->for($curriculum)->for($specTarget, 'courseSpecification')->create([
            'year_level' => '1',
            'term_type' => Term::TypeSecondSemester,
            'term_label' => 'Second Semester',
            'sequence' => 5,
        ]);

        CourseRequirement::query()->create([
            'course_specification_id' => $specTarget->id,
            'rule_type' => CourseRequirement::TypePrerequisite,
            'group_key' => 'g1',
            'sequence' => 1,
            'related_course_id' => $course1->id,
            'state' => CourseRequirement::StateActive,
        ]);

        return [
            'program' => $program,
            'curriculum' => $curriculum,
            'course1' => $course1,
            'course2' => $course2,
            'courseCredit' => $courseCredit,
            'coursePe' => $coursePe,
            'courseTarget' => $courseTarget,
            'spec1' => $spec1,
            'spec2' => $spec2,
            'specCredit' => $specCredit,
            'specPe' => $specPe,
            'specTarget' => $specTarget,
            'entry1' => $entry1,
            'entry2' => $entry2,
            'entryCredit' => $entryCredit,
            'entryPe' => $entryPe,
            'entryTarget' => $entryTarget,
        ];
    }

    private function staff(string $roleName): User
    {
        Role::findOrCreate('student', 'web');
        $role = Role::findOrCreate($roleName, 'web');
        if ($roleName === User::StaffRoleRegistrar) {
            $role->syncPermissions([
                Permission::findOrCreate('manage-schedules', 'web'),
                Permission::findOrCreate('approve-documents', 'web'),
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);
        $this->actingAs($user)->withSession([WorkspaceContextResolver::SessionKey => $roleName]);

        return $user;
    }
}
