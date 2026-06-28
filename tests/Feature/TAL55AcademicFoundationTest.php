<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ImportBatch;
use App\Models\Program;
use App\Models\Term;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TAL55AcademicFoundationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
    }

    public function test_academic_year_owns_terms_and_terms_own_calendar_events(): void
    {
        $academicYear = AcademicYear::factory()->create([
            'label' => '2026-2027',
            'state' => AcademicYear::StateActive,
        ]);
        $term = Term::factory()->for($academicYear)->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
            'state' => Term::StateActive,
        ]);
        $event = CalendarEvent::factory()->for($term)->create([
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'process_key' => 'enrollment',
            'blocks_scheduling' => false,
        ]);

        $this->assertTrue($academicYear->terms->contains($term));
        $this->assertTrue($term->calendarEvents->contains($event));
        $this->assertTrue($event->term->is($term));
        $this->assertSame('2026-2027 | Active', $academicYear->displayLabel());
    }

    public function test_course_specifications_own_components_and_structured_requirements(): void
    {
        $course = Course::factory()->create(['code' => 'IT101']);
        $prerequisiteCourse = Course::factory()->create(['code' => 'MATH101']);
        $specification = CourseSpecification::factory()->for($course)->create([
            'revision_code' => '2026A',
            'title' => 'Introduction to Computing',
            'state' => CourseSpecification::StateActive,
            'allowed_modalities' => ['FACE_TO_FACE', 'ONLINE'],
        ]);
        $lecture = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => 3.00,
            'sequence' => 1,
        ]);
        $laboratory = CourseComponent::factory()->for($specification)->create([
            'component_type' => CourseComponent::TypeLaboratory,
            'weekly_contact_hours' => 2.00,
            'room_type_default' => 'COMPUTER_LABORATORY',
            'sequence' => 2,
        ]);
        $requirement = CourseRequirement::factory()
            ->for($specification)
            ->for($prerequisiteCourse, 'relatedCourse')
            ->create([
                'rule_type' => CourseRequirement::TypePrerequisite,
                'group_key' => 'G1',
                'required_outcome' => 'PASSED',
                'accepts_transfer_credit' => true,
            ]);

        $this->assertTrue($course->specifications->contains($specification));
        $this->assertTrue($specification->components->contains($lecture));
        $this->assertTrue($specification->components->contains($laboratory));
        $this->assertTrue($specification->requirements->contains($requirement));
        $this->assertTrue($requirement->relatedCourse->is($prerequisiteCourse));
        $this->assertSame(['FACE_TO_FACE', 'ONLINE'], $specification->allowed_modalities);
    }

    public function test_program_curriculum_versions_entries_and_course_specification_placement(): void
    {
        $program = Program::factory()->create(['code' => 'BSIT']);
        $effectiveTerm = Term::factory()->create([
            'type' => Term::TypeFirstSemester,
            'label' => 'First Semester',
        ]);
        $specification = CourseSpecification::factory()->create([
            'title' => 'Programming Fundamentals',
        ]);
        $curriculumVersion = CurriculumVersion::factory()
            ->for($program)
            ->for($effectiveTerm, 'effectiveEntryTerm')
            ->create([
                'version_code' => 'BSIT-2026',
                'state' => CurriculumVersion::StateRecordedApproved,
            ]);
        $entry = CurriculumEntry::factory()
            ->for($curriculumVersion)
            ->for($specification, 'courseSpecification')
            ->create([
                'year_level' => 'First Year',
                'term_label' => 'First Semester',
                'term_type' => Term::TypeFirstSemester,
                'sequence' => 1,
                'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            ]);

        $this->assertTrue($program->curriculumVersions->contains($curriculumVersion));
        $this->assertTrue($curriculumVersion->entries->contains($entry));
        $this->assertTrue($entry->courseSpecification->is($specification));
        $this->assertTrue($curriculumVersion->effectiveEntryTerm->is($effectiveTerm));
        $this->assertSame('First Year', $entry->year_level);
        $this->assertSame(Term::TypeFirstSemester, $entry->term_type);
    }

    public function test_import_batches_store_academic_import_metadata_without_legacy_import_columns(): void
    {
        $batch = ImportBatch::factory()->create([
            'type' => ImportBatch::TypeCurriculum,
            'template_version' => 'curriculum-v1',
            'row_count' => 12,
            'warning_count' => 1,
            'validation_details' => [
                'warnings' => [
                    ['row' => 4, 'message' => 'Computed total units mismatch.'],
                ],
            ],
        ]);

        $this->assertSame(ImportBatch::TypeCurriculum, $batch->type);
        $this->assertSame(12, $batch->row_count);
        $validationDetails = $batch->getAttribute('validation_details');

        $this->assertIsArray($validationDetails);
        $this->assertSame('Computed total units mismatch.', $validationDetails['warnings'][0]['message']);
        $this->assertFalse(Schema::hasColumn('import_batches', 'import_type'));
        $this->assertFalse(Schema::hasColumn('import_batches', 'error_log'));
    }
}
