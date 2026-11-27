<?php

namespace Tests\Feature;

use App\Actions\Completion\TranscriptPreview;
use App\Filament\Resources\AdmissionCycles\Pages\CreateAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\Pages\EditAdmissionCycle;
use App\Filament\Resources\StudentProfiles\Pages\ImportOngoingStudentsSpreadsheet;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Student\Pages\Profile as StudentHubProfilePage;
use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\DegreeConferral;
use App\Models\Enrollment;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use App\Models\Program;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\TranscriptRequest;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Issue93StudentBatchIdentityAndImportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_importer_defaults_to_latest_batch_and_infers_matching_batch_from_student_numbers(): void
    {
        $registrar = $this->registrar();
        [$program, $curriculum, $ay2024, $ay2025] = $this->multiBatchCurriculumFixture();

        $csv2024 = $this->csvUpload('batch-2024.csv', [
            ['Student Number*', 'First Name*', 'Middle Name', 'Last Name*', 'Email Address*', 'Program Code', 'Birthdate', 'Mobile Number', 'Address'],
            ['SIA-2024-0011', 'Juan', 'Santos', 'Dela Cruz', 'juan.2024@servitech.test', $program->code, '2005-04-12', '09171234567', 'Cavite'],
        ]);
        $csv2025 = $this->csvUpload('batch-2025.csv', [
            ['Student Number*', 'First Name*', 'Middle Name', 'Last Name*', 'Email Address*', 'Program Code', 'Birthdate', 'Mobile Number', 'Address'],
            ['SIA-2025-0024', 'Maria', 'Reyes', 'Santos', 'maria.2025@servitech.test', $program->code, '2006-08-21', '09179876543', 'Laguna'],
        ]);

        // Without an uploaded file, a multi-batch curriculum defaults to the latest linked batch (AY 2025-2026).
        $page = Livewire::actingAs($registrar)
            ->test(ImportOngoingStudentsSpreadsheet::class)
            ->set('programId', $program->id)
            ->set('curriculumVersionId', $curriculum->id)
            ->assertSet('entryAcademicYearId', $ay2025->id);

        // Uploading SIA-2024-* rows infers AY 2024-2025 when the user has not pinned a batch.
        $page->set('uploadedFile', $csv2024)
            ->assertSet('entryAcademicYearId', $ay2024->id);

        // Uploading SIA-2025-* rows infers AY 2025-2026 when the user has not pinned a batch.
        $page->set('uploadedFile', $csv2025)
            ->assertSet('entryAcademicYearId', $ay2025->id);

        // Explicitly choosing an entry batch must not be overridden by subsequent file upload.
        $csvOverrideCheck = $this->csvUpload('override-check.csv', [
            ['Student Number*', 'First Name*', 'Middle Name', 'Last Name*', 'Email Address*', 'Program Code', 'Birthdate', 'Mobile Number', 'Address'],
            ['SIA-2025-0024', 'Maria', 'Reyes', 'Santos', 'maria.2025@servitech.test', $program->code, '2006-08-21', '09179876543', 'Laguna'],
        ]);

        Livewire::actingAs($registrar)
            ->test(ImportOngoingStudentsSpreadsheet::class)
            ->set('programId', $program->id)
            ->set('curriculumVersionId', $curriculum->id)
            ->set('entryAcademicYearId', $ay2024->id)
            ->set('uploadedFile', $csvOverrideCheck)
            ->assertSet('entryAcademicYearId', $ay2024->id);
    }

    public function test_student_profile_surfaces_show_entry_batch_instead_of_stale_curriculum_suffix(): void
    {
        $registrar = $this->registrar();
        [$program, $curriculum, , $ay2025] = $this->multiBatchCurriculumFixture();

        $studentUser = User::factory()->create(['status' => User::StatusActive, 'name' => 'Maria Reyes Santos']);
        $studentUser->assignRole($this->findOrCreateRole('student'));

        $student = StudentProfile::factory()->for($studentUser)->for($program)->create([
            'student_number' => 'SIA-2025-0024',
            'curriculum_version_id' => $curriculum->id,
            'entry_academic_year_id' => $ay2025->id,
        ]);

        $expectedLabel = 'BSIT Curriculum · Batch 2025–26';
        $this->assertSame($expectedLabel, StudentProfileResource::curriculumAndBatchLabel($student));

        Livewire::actingAs($registrar)
            ->test(ListStudentProfiles::class)
            ->searchTable($student->student_number)
            ->assertSee($expectedLabel)
            ->assertDontSee('Batch 2024–25');

        Livewire::actingAs($registrar)
            ->test(ViewStudentProfile::class, ['record' => $student->id])
            ->assertSee($expectedLabel)
            ->assertDontSee('Batch 2024–25');

        Filament::setCurrentPanel(Filament::getPanel('student'));
        Livewire::actingAs($studentUser)
            ->test(StudentHubProfilePage::class)
            ->assertSee($expectedLabel)
            ->assertSee('Batch 2025–26')
            ->assertDontSee('Batch 2024–25');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $unofficialHtml = view('outputs.unofficial-student-record', [
            'student' => $student,
            'recordPeriods' => [],
            'cumulative' => ['value' => '—', 'through' => null, 'reason' => null],
            'curriculum' => ['completed_units' => 0, 'deficiency_count' => 0],
            'asOf' => now('Asia/Manila'),
        ])->render();

        $this->assertStringContainsString($expectedLabel, $unofficialHtml);
        $this->assertStringNotContainsString('Batch 2024–25', $unofficialHtml);

        $term = Term::factory()->for($ay2025)->create([
            'label' => 'First Semester',
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
        ]);
        $entry = CurriculumEntry::factory()->for($curriculum)->create(['year_level' => '1']);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $enrollment = Enrollment::factory()->for($student)->for($term)->create([
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $timetable = PublishedTimetableVersion::factory()->for($term)->create();
        $courseEnrollment = CourseEnrollment::factory()->for($enrollment)->for($offering, 'termOffering')->for($section)
            ->for($timetable, 'publishedTimetableVersion')->create();
        $roster = GradeRoster::factory()->for($offering, 'termOffering')->for($section)->create();
        $rosterRow = GradeRosterRow::factory()->for($roster, 'roster')->for($courseEnrollment, 'courseEnrollment')->create();
        GradeOutcomeEvent::factory()->for($rosterRow, 'row')->create([
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'result_code' => '1.75',
            'new_value' => '1.75',
            'released_at' => now(),
        ]);

        $conferral = DegreeConferral::factory()->for($student)->create([
            'curriculum_version_id' => $curriculum->id,
        ]);
        $torRequest = TranscriptRequest::factory()->for($student)->create([
            'degree_conferral_id' => $conferral->id,
        ]);

        config([
            'institution.name' => 'SERVITECH INSTITUTE ASIA INC.',
            'institution.address' => '99 Servitech Campus Blvd',
            'institution.public.support_phone' => '09170000000',
        ]);

        $torPreview = app(TranscriptPreview::class)->forRequest($torRequest);
        $this->assertSame($expectedLabel, $torPreview['student']['curriculum_version']);
        $this->assertStringNotContainsString($curriculum->version_code, $torPreview['student']['curriculum_version']);
    }

    public function test_registrar_can_correct_entry_academic_year_on_view_student_profile(): void
    {
        $registrar = $this->registrar();
        [$program, $curriculum, $ay2024, $ay2025] = $this->multiBatchCurriculumFixture();

        $student = StudentProfile::factory()->for($program)->create([
            'student_number' => 'SIA-2025-0024',
            'curriculum_version_id' => $curriculum->id,
            'entry_academic_year_id' => $ay2024->id,
        ]);

        Livewire::actingAs($registrar)
            ->test(ViewStudentProfile::class, ['record' => $student->id])
            ->callAction('recordCorrection', [
                'entry_academic_year_id' => $ay2025->id,
                'reason' => 'Align entry batch with 2025-2026 student number intake.',
                'authority_reference' => 'REG-BATCH-2025',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame($ay2025->id, $student->fresh()->entry_academic_year_id);
        $this->assertDatabaseHas('student_profile_events', [
            'student_profile_id' => $student->id,
            'event_type' => 'Correction',
        ]);
    }

    public function test_record_student_section_action_scopes_cohorts_to_matching_curriculum_and_year_level(): void
    {
        $registrar = $this->registrar();
        [$program, $curriculumA, $ay2024, $ay2025] = $this->multiBatchCurriculumFixture();

        $curriculumB = CurriculumVersion::factory()->for($program)->create([
            'name' => 'BSIT Revised Curriculum',
            'state' => CurriculumVersion::StateActive,
        ]);

        $term = Term::factory()->for($ay2025)->create([
            'label' => 'First Semester',
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
        ]);

        $student = StudentProfile::factory()->for($program)->create([
            'student_number' => 'SIA-2025-0091',
            'curriculum_version_id' => $curriculumA->id,
            'entry_academic_year_id' => $ay2025->id,
        ]);

        $matchingCohort = TermCohort::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculumA->id,
            'year_level' => 1,
            'reference' => 'BSIT-Y1-MATCH',
        ]);
        $nullYearCohort = TermCohort::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculumA->id,
            'year_level' => null,
            'reference' => 'BSIT-ANY-YEAR',
        ]);
        $wrongCurriculumCohort = TermCohort::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculumB->id,
            'year_level' => 1,
            'reference' => 'BSIT-WRONG-CURR',
        ]);
        $wrongYearCohort = TermCohort::factory()->create([
            'term_id' => $term->id,
            'program_id' => $program->id,
            'curriculum_version_id' => $curriculumA->id,
            'year_level' => 3,
            'reference' => 'BSIT-Y3-WRONG',
        ]);

        $enrollment = Enrollment::factory()->for($student)->for($term)->create([
            'status' => 'officially_enrolled',
            'canonical_outcome' => Enrollment::OutcomeOfficiallyEnrolled,
            'officially_enrolled_at' => now(),
        ]);
        $entry = CurriculumEntry::factory()->for($curriculumA)->create(['year_level' => '1']);
        $offering = TermOffering::factory()->for($term)->for($entry, 'curriculumEntry')->create();
        $section = Section::factory()->for($offering, 'termOffering')->create();
        $timetable = PublishedTimetableVersion::query()->where('term_id', $term->id)->first()
            ?? PublishedTimetableVersion::factory()->for($term)->create();
        CourseEnrollment::factory()->for($enrollment)->for($offering, 'termOffering')->for($section)
            ->for($timetable, 'publishedTimetableVersion')->create();

        Livewire::actingAs($registrar)
            ->test(ViewStudentProfile::class, ['record' => $student->id])
            ->mountAction('recordStudentSection')
            ->setActionData(['enrollment_id' => $enrollment->id])
            ->assertFormFieldExists('student_section_id', function (Select $field) use (
                $matchingCohort,
                $nullYearCohort,
                $wrongCurriculumCohort,
                $wrongYearCohort
            ): bool {
                $optionKeys = array_map('intval', array_keys($field->getOptions()));

                return in_array($matchingCohort->id, $optionKeys, true)
                    && in_array($nullYearCohort->id, $optionKeys, true)
                    && ! in_array($wrongCurriculumCohort->id, $optionKeys, true)
                    && ! in_array($wrongYearCohort->id, $optionKeys, true);
            });
    }

    public function test_admission_cycle_form_excludes_non_operational_historical_terms_on_create_and_retains_on_edit(): void
    {
        $registrar = $this->registrar();
        $permission = Permission::query()->where('name', 'manage-admission-setup')->where('guard_name', 'web')->first()
            ?? Permission::findOrCreate('manage-admission-setup', 'web');
        $registrarRole = $this->findOrCreateRole(User::StaffRoleRegistrar);
        if (! $registrarRole->hasPermissionTo($permission)) {
            $registrarRole->givePermissionTo($permission);
        }

        $operationalAy = AcademicYear::factory()->create([
            'label' => '2098-2099',
            'starts_on' => '2098-08-01',
            'ends_on' => '2099-05-31',
            'state' => AcademicYear::StateActive,
            'is_operational' => true,
        ]);
        $operationalTerm = Term::factory()->for($operationalAy)->create([
            'label' => 'First Semester',
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'starts_on' => '2098-08-10',
            'ends_on' => '2098-12-20',
        ]);

        $historicalAy = AcademicYear::factory()->create([
            'label' => '2024-2025',
            'starts_on' => '2024-08-01',
            'ends_on' => '2025-05-31',
            'state' => AcademicYear::StateClosed,
            'is_operational' => false,
        ]);
        $historicalTerm = Term::factory()->for($historicalAy)->create([
            'label' => 'First Semester',
            'type' => Term::TypeFirstSemester,
            'state' => Term::StateActive,
            'starts_on' => '2024-08-10',
            'ends_on' => '2024-12-20',
        ]);

        Livewire::actingAs($registrar)
            ->test(CreateAdmissionCycle::class)
            ->assertFormFieldExists('term_id', function (Select $field) use ($operationalTerm, $historicalTerm): bool {
                $optionKeys = array_map('intval', array_keys($field->getOptions()));

                return in_array($operationalTerm->id, $optionKeys, true)
                    && ! in_array($historicalTerm->id, $optionKeys, true);
            });

        $existingCycle = AdmissionCycle::factory()->for($historicalTerm, 'term')->create([
            'registrar_owner_id' => $registrar->id,
        ]);

        Livewire::actingAs($registrar)
            ->test(EditAdmissionCycle::class, ['record' => $existingCycle->id])
            ->assertFormFieldExists('term_id', function (Select $field) use ($operationalTerm, $historicalTerm): bool {
                $optionKeys = array_map('intval', array_keys($field->getOptions()));

                return in_array($operationalTerm->id, $optionKeys, true)
                    && in_array($historicalTerm->id, $optionKeys, true);
            });
    }

    /**
     * @return array{0: Program, 1: CurriculumVersion, 2: AcademicYear, 3: AcademicYear}
     */
    private function multiBatchCurriculumFixture(): array
    {
        $program = Program::factory()->create([
            'code' => 'BSIT-'.fake()->unique()->numerify('###'),
            'name' => 'Bachelor of Science in Information Technology',
            'is_active' => true,
        ]);

        $ay2024 = AcademicYear::factory()->create([
            'label' => '2024-2025',
            'starts_on' => '2024-08-01',
            'ends_on' => '2025-05-31',
            'state' => AcademicYear::StateClosed,
            'is_operational' => false,
        ]);
        $ay2025 = AcademicYear::factory()->create([
            'label' => '2025-2026',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-05-31',
            'state' => AcademicYear::StateActive,
            'is_operational' => true,
        ]);

        $curriculum = CurriculumVersion::factory()->for($program)->create([
            'version_code' => 'INTERNAL-BSIT-'.fake()->unique()->numerify('###'),
            'name' => 'BSIT Curriculum · Batch 2024–25',
            'state' => CurriculumVersion::StateActive,
        ]);
        $curriculum->academicYears()->attach($ay2024->id, ['program_id' => $program->id]);
        $curriculum->academicYears()->attach($ay2025->id, ['program_id' => $program->id]);

        return [$program, $curriculum, $ay2024, $ay2025];
    }

    private function findOrCreateRole(string $name): Role
    {
        return Role::query()->where('name', $name)->where('guard_name', 'web')->first()
            ?? Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    private function registrar(): User
    {
        $role = $this->findOrCreateRole(User::StaffRoleRegistrar);
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csvUpload(string $originalName, array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+b');
        $this->assertNotFalse($handle);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);
        $this->assertIsString($content);

        return UploadedFile::fake()->createWithContent($originalName, $content);
    }
}
