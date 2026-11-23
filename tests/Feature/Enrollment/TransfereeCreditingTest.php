<?php

namespace Tests\Feature\Enrollment;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Enrollment\CorrectRegistrationCreditEvaluation;
use App\Actions\Enrollment\RecordRegistrationCreditEvaluation;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Filament\Resources\Enrollments\Pages\ViewEnrollment;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Models\AdmissionApplication;
use App\Models\ApplicationSubmissionVersion;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationCreditLine;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
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
        [$credited, $bridged, $needed] = $entries;

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $component = Livewire::actingAs($this->staff(User::StaffRoleRegistrar))
            ->test(ViewEnrollment::class, ['record' => $case->getRouteKey()])
            ->mountAction('recordAcademicEvaluation')
            ->assertMountedActionModalSee('Is the previous school’s curriculum the same as SIA’s?')
            ->assertMountedActionModalSee('Year 1 · First Semester')
            ->assertMountedActionModalSee($needed->courseSpecification->course->code);
        $component->setActionData([
            'curriculum_version_id' => $curriculum->id,
            'same_curriculum' => 'different',
            'rows' => [
                'e'.$credited->id => ['status' => 'credited', 'tor_grade' => '1.75', 'source_course_code' => 'CS 101', 'source_course_title' => 'Computer Fundamentals', 'source_units' => '3'],
                'e'.$bridged->id => ['status' => 'bridging', 'tor_grade' => '2.00', 'source_course_code' => 'MATH 1', 'source_course_title' => 'College Algebra', 'source_units' => '2', 'bridging_units' => '1'],
                'e'.$needed->id => ['status' => 'need'],
            ],
            'authority_reference' => 'SYN-CREDITING-MEMO',
            'approved_on' => today()->toDateString(),
        ]);
        $component->callMountedAction()->assertHasNoActionErrors()->assertNotified('Academic evaluation recorded');

        $evaluation = RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->sole();
        $this->assertSame([$credited->id, $bridged->id], $evaluation->credited_curriculum_entry_ids);
        $this->assertFalse($evaluation->same_curriculum);
        $lines = $evaluation->creditLines()->get()->keyBy('curriculum_entry_id');
        $this->assertSame(['CS 101', '1.75', null], [$lines[$credited->id]->source_course_code, $lines[$credited->id]->tor_grade, $lines[$credited->id]->bridging_units]);
        $this->assertSame(['2.00', '1.00'], [$lines[$bridged->id]->source_units, $lines[$bridged->id]->bridging_units]);
        $this->assertSame('Transfer credit from MATH 1 · 1 unit(s) bridging', $lines[$bridged->id]->transcriptRemark());
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
            'bridging above the SIA units' => [['tor_grade' => '1.50', 'source_course_code' => 'CS 101', 'source_course_title' => 'Fundamentals', 'bridging_units' => '4'], false],
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
