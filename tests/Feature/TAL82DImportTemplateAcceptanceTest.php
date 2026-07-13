<?php

namespace Tests\Feature;

use App\Actions\Imports\AcademicImportCsv;
use App\Actions\Imports\AcademicImportService;
use App\Actions\Imports\CourseSpecificationImportTemplate;
use App\Actions\Imports\CurriculumImportTemplate;
use App\Actions\Imports\ImportBatchLifecycleService;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\ImportBatches\Pages\ListImportBatches;
use App\Filament\Resources\ImportBatches\Pages\ViewImportBatch;
use App\Models\Course;
use App\Models\CourseComponent;
use App\Models\CourseRequirement;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\ImportBatch;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TAL82DImportTemplateAcceptanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        foreach ([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
            User::StaffRoleSystemSuperAdmin,
        ] as $role) {
            Role::query()->firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Storage::fake(AcademicImportService::Disk);
    }

    #[Test]
    public function current_templates_retain_template_version_and_exact_csv_headers(): void
    {
        $this->assertSame('template_version', CourseSpecificationImportTemplate::headers()[0]);
        $this->assertSame('template_version', CurriculumImportTemplate::headers()[0]);
        $this->assertContains('component_type', CourseSpecificationImportTemplate::headers());
        $this->assertContains('required_room_feature_keys', CourseSpecificationImportTemplate::headers());
        $this->assertContains('course_revision_code', CurriculumImportTemplate::headers());
        $this->assertContains('course_title', CurriculumImportTemplate::headers());
        $this->assertContains('course_units', CurriculumImportTemplate::headers());
        $this->assertContains('prerequisite_course_codes', CurriculumImportTemplate::headers());
        $this->assertStringContainsString(CourseSpecificationImportTemplate::Version, CourseSpecificationImportTemplate::csv());
        $this->assertStringContainsString(CurriculumImportTemplate::Version, CurriculumImportTemplate::csv());
    }

    #[Test]
    public function strict_header_validation_blocks_missing_or_duplicate_headers_before_rows_are_posted(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $path = AcademicImportService::CourseSpecificationDirectory.'/bad-header.csv';
        $headers = CourseSpecificationImportTemplate::headers();
        $headers[0] = 'course_code';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            $headers,
            $this->validCourseSpecificationRow(),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);

        $this->assertSame(0, $batch->row_count);
        $this->assertGreaterThanOrEqual(1, $batch->error_count);
        $this->assertSame(ImportBatch::StatePendingReview, $batch->state);

        $this->expectException(ValidationException::class);

        app(ImportBatchLifecycleService::class)->post($batch, $registrar);
    }

    #[Test]
    public function course_specification_import_posts_draft_records_without_overwriting_active_revisions(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        Course::factory()->create(['code' => 'IT100', 'state' => Course::StateActive]);
        $path = AcademicImportService::CourseSpecificationDirectory.'/course-spec.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow([
                'course_code' => 'IT101',
                'component_type' => CourseComponent::TypeLecture,
                'weekly_contact_hours' => '2.00',
                'component_sequence' => '1',
                'prerequisite_course_codes' => 'IT100',
            ]),
            $this->validCourseSpecificationRow([
                'course_code' => 'IT101',
                'component_type' => CourseComponent::TypeLaboratory,
                'weekly_contact_hours' => '1.00',
                'room_type_default' => CourseComponent::RoomTypeComputerLaboratory,
                'required_room_feature_keys' => 'COMPUTER_UNITS|PROJECTOR',
                'component_sequence' => '2',
                'prerequisite_course_codes' => 'IT100',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);

        $this->assertSame(2, $batch->row_count);
        $this->assertSame(0, $batch->error_count);
        $this->assertSame(0, $batch->warning_count);

        $posted = app(ImportBatchLifecycleService::class)->post($batch, $registrar);

        $this->assertSame(ImportBatch::StatePosted, $posted->state);
        $course = Course::query()->where('code', 'IT101')->firstOrFail();
        $specification = CourseSpecification::query()
            ->where('course_id', $course->id)
            ->where('revision_code', '2026-DRAFT')
            ->firstOrFail();

        $this->assertSame(CourseSpecification::StateDraft, $specification->state);
        $this->assertSame(2, $specification->components()->count());
        $this->assertSame(
            ['COMPUTER_UNITS', 'PROJECTOR'],
            $specification->components()->where('component_type', CourseComponent::TypeLaboratory)->sole()->required_room_feature_keys,
        );
        $this->assertSame(1, $specification->requirements()->count());

        $activeCourse = Course::factory()->create(['code' => 'IT102']);
        CourseSpecification::factory()->create([
            'course_id' => $activeCourse->id,
            'revision_code' => '2026-DRAFT',
            'state' => CourseSpecification::StateActive,
        ]);
        $activePath = AcademicImportService::CourseSpecificationDirectory.'/active-conflict.csv';
        Storage::disk(AcademicImportService::Disk)->put($activePath, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow(['course_code' => 'IT102']),
        ]));

        $conflict = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $activePath, $registrar);

        $this->assertGreaterThanOrEqual(1, $conflict->error_count);
        $this->assertSame(1, CourseSpecification::query()
            ->where('course_id', $activeCourse->id)
            ->where('revision_code', '2026-DRAFT')
            ->where('state', CourseSpecification::StateActive)
            ->count());
    }

    #[Test]
    public function warnings_require_acknowledgement_before_draft_creation(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $path = AcademicImportService::CourseSpecificationDirectory.'/course-spec-warning.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow([
                'credit_units' => '4.00',
                'weekly_contact_hours' => '3.00',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);

        $this->assertSame(0, $batch->error_count);
        $this->assertGreaterThanOrEqual(1, $batch->warning_count);

        try {
            app(ImportBatchLifecycleService::class)->post($batch, $registrar);
            $this->fail('Posting without warning acknowledgement should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('warnings', $exception->errors());
        }

        $acknowledged = app(ImportBatchLifecycleService::class)->acknowledgeWarnings($batch, $registrar);
        $posted = app(ImportBatchLifecycleService::class)->post($acknowledged, $registrar);

        $this->assertNotNull($acknowledged->acknowledged_at);
        $this->assertSame(ImportBatch::StatePosted, $posted->state);
    }

    #[Test]
    public function ambiguous_course_requirements_are_retained_as_errors_and_cannot_be_posted(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        Course::factory()->create(['code' => 'IT100']);
        Course::factory()->create(['code' => 'IT102']);
        $requirementCount = CourseRequirement::query()->count();
        $path = AcademicImportService::CourseSpecificationDirectory.'/ambiguous-requirements.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow([
                'course_code' => 'IT103',
                'prerequisite_course_codes' => 'IT100 or IT102',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
        $details = $batch->getAttribute('validation_details');

        $this->assertIsArray($details);
        $this->assertGreaterThanOrEqual(1, $batch->error_count);
        $this->assertSame(0, $batch->warning_count);
        $this->assertSame('IT100 or IT102', $details['errors'][0]['values']['prerequisite_course_codes']);

        try {
            app(ImportBatchLifecycleService::class)->post($batch, $registrar);
            $this->fail('Ambiguous course requirements must block posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('errors', $exception->errors());
        }

        $this->assertDatabaseMissing('courses', ['code' => 'IT103']);
        $this->assertSame(0, CourseSpecification::query()->whereHas('course', fn ($query) => $query->where('code', 'IT103'))->count());
        $this->assertSame($requirementCount, CourseRequirement::query()->count());
    }

    #[Test]
    public function repeated_course_specification_rows_must_have_consistent_shared_fields(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        Course::factory()->create(['code' => 'IT100']);
        Course::factory()->create(['code' => 'IT102']);
        $path = AcademicImportService::CourseSpecificationDirectory.'/inconsistent-course-spec.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow([
                'component_type' => CourseComponent::TypeLecture,
                'component_sequence' => '1',
                'prerequisite_course_codes' => 'IT100',
            ]),
            $this->validCourseSpecificationRow([
                'title' => 'Contradictory title',
                'component_type' => CourseComponent::TypeLaboratory,
                'component_sequence' => '2',
                'prerequisite_course_codes' => 'IT102',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
        $details = $batch->getAttribute('validation_details');

        $this->assertIsArray($details);
        $messages = collect($details['errors'])->pluck('message')->implode(' ');

        $this->assertGreaterThanOrEqual(2, $batch->error_count);
        $this->assertStringContainsString('Title', $messages);
        $this->assertStringContainsString('Prerequisite Course Codes', $messages);

        $this->expectException(ValidationException::class);
        app(ImportBatchLifecycleService::class)->post($batch, $registrar);
    }

    #[Test]
    public function repeated_curriculum_rows_must_have_consistent_shared_fields_and_client_totals(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'BSIT']);
        $this->courseSpecification('IT101', '2026-DRAFT', '3.00');
        $this->courseSpecification('IT102', '2026-DRAFT', '2.00');
        $path = AcademicImportService::CurriculumDirectory.'/inconsistent-curriculum.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CurriculumImportTemplate::headers(),
            $this->validCurriculumRow([
                'program_code' => $program->code,
                'course_code' => 'IT101',
                'sequence' => '1',
                'client_total_units' => '5.00',
            ]),
            $this->validCurriculumRow([
                'program_code' => $program->code,
                'curriculum_name' => 'Contradictory curriculum name',
                'course_code' => 'IT102',
                'course_units' => '2.00',
                'sequence' => '2',
                'client_total_units' => '6.00',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCurriculum, $path, $registrar);
        $details = $batch->getAttribute('validation_details');

        $this->assertIsArray($details);
        $messages = collect($details['errors'])->pluck('message')->implode(' ');

        $this->assertGreaterThanOrEqual(2, $batch->error_count);
        $this->assertStringContainsString('Curriculum Name', $messages);
        $this->assertStringContainsString('Client Total Units', $messages);

        $this->expectException(ValidationException::class);
        app(ImportBatchLifecycleService::class)->post($batch, $registrar);
    }

    #[Test]
    public function consistent_client_total_mismatch_requires_acknowledgement_and_system_units_remain_authoritative(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'BSIT']);
        $this->courseSpecification('IT101', '2026-DRAFT', '3.00');
        $this->courseSpecification('IT102', '2026-DRAFT', '2.00');
        $path = AcademicImportService::CurriculumDirectory.'/subtotal-mismatch.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CurriculumImportTemplate::headers(),
            $this->validCurriculumRow([
                'program_code' => $program->code,
                'course_code' => 'IT101',
                'sequence' => '1',
                'client_total_units' => '6.00',
            ]),
            $this->validCurriculumRow([
                'program_code' => $program->code,
                'course_code' => 'IT102',
                'course_units' => '2.00',
                'sequence' => '2',
                'client_total_units' => '6.00',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCurriculum, $path, $registrar);
        $details = $batch->getAttribute('validation_details');

        $this->assertIsArray($details);
        $warnings = collect($details['warnings'])->pluck('message')->implode(' ');

        $this->assertSame(0, $batch->error_count);
        $this->assertGreaterThanOrEqual(1, $batch->warning_count);
        $this->assertStringContainsString('system-computed subtotal 5.00', $warnings);
        $this->assertStringContainsString('Client Total Units 6.00', $warnings);

        try {
            app(ImportBatchLifecycleService::class)->post($batch, $registrar);
            $this->fail('A subtotal mismatch warning must be acknowledged before posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('warnings', $exception->errors());
        }

        $acknowledged = app(ImportBatchLifecycleService::class)->acknowledgeWarnings($batch, $registrar);
        app(ImportBatchLifecycleService::class)->post($acknowledged, $registrar);

        $version = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('version_code', 'BSIT-2026-DRAFT')
            ->firstOrFail();

        $this->assertSame(2, $version->entries()->count());
        $this->assertSame(5.0, (float) $version->entries()->with('courseSpecification')->get()->sum(
            fn (CurriculumEntry $entry): float => (float) $entry->courseSpecification->credit_units,
        ));
    }

    #[Test]
    public function curriculum_import_posts_draft_version_and_entries_without_activation(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'BSIT']);
        $course = Course::factory()->create(['code' => 'IT101']);
        CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'revision_code' => '2026-DRAFT',
            'title' => 'Introduction to Computing',
            'credit_units' => '3.00',
            'state' => CourseSpecification::StateDraft,
        ]);
        $path = AcademicImportService::CurriculumDirectory.'/curriculum.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CurriculumImportTemplate::headers(),
            $this->validCurriculumRow(['program_code' => $program->code]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCurriculum, $path, $registrar);

        $this->assertSame(0, $batch->error_count);

        app(ImportBatchLifecycleService::class)->post($batch, $registrar);

        $version = CurriculumVersion::query()
            ->where('program_id', $program->id)
            ->where('version_code', 'BSIT-2026-DRAFT')
            ->firstOrFail();

        $this->assertSame(CurriculumVersion::StateDraft, $version->state);
        $this->assertNull($version->approved_at);
        $this->assertDatabaseHas('curriculum_entries', [
            'curriculum_version_id' => $version->id,
            'year_level' => '1',
            'term_type' => Term::TypeFirstSemester,
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
        ]);
    }

    #[Test]
    public function review_surface_and_download_expose_complete_row_numbered_findings(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $path = AcademicImportService::CourseSpecificationDirectory.'/complete-review.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow(['course_code' => 'IT201', 'credit_units' => 'bad']),
            $this->validCourseSpecificationRow(['course_code' => 'IT202', 'weekly_contact_hours' => '0']),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
        $details = $batch->getAttribute('validation_details');

        $this->assertIsArray($details);
        $this->assertCount(2, $details['rows']);
        $this->assertSame(2, $details['rows'][0]['row']);
        $this->assertSame(3, $details['rows'][1]['row']);

        $csv = app(AcademicImportService::class)->validationFindingsCsv($batch, $registrar);

        $this->assertStringContainsString('severity,source_row,message,source_values', $csv);
        $this->assertStringContainsString('ERROR,2,', $csv);
        $this->assertStringContainsString('ERROR,3,', $csv);

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewImportBatch::class, ['record' => $batch->getRouteKey()])
            ->assertSee('Complete Row Preview')
            ->assertSee('Validation Errors')
            ->assertSee('IT201')
            ->assertSee('IT202');
    }

    #[Test]
    public function course_specification_post_revalidates_active_conflicts_created_after_preview(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $path = AcademicImportService::CourseSpecificationDirectory.'/stale-course-preview.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow(['course_code' => 'IT301']),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
        $course = Course::factory()->create(['code' => 'IT301']);
        $active = CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'revision_code' => '2026-DRAFT',
            'title' => 'Authoritative active title',
            'state' => CourseSpecification::StateActive,
        ]);

        try {
            app(ImportBatchLifecycleService::class)->post($batch, $registrar);
            $this->fail('An Active revision created after preview must block posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stale_preview', $exception->errors());
        }

        $this->assertSame('Authoritative active title', $active->fresh()->title);
        $this->assertSame(1, CourseSpecification::query()->where('course_id', $course->id)->count());
        $this->assertSame(ImportBatch::StatePendingReview, $batch->fresh()->state);
    }

    #[Test]
    public function curriculum_post_revalidates_active_conflicts_created_after_preview(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'BSIT']);
        $specification = $this->courseSpecification('IT401', '2026-DRAFT', '3.00');
        $path = AcademicImportService::CurriculumDirectory.'/stale-curriculum-preview.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CurriculumImportTemplate::headers(),
            $this->validCurriculumRow([
                'program_code' => $program->code,
                'course_code' => 'IT401',
                'course_title' => $specification->title,
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCurriculum, $path, $registrar);
        $active = CurriculumVersion::factory()->create([
            'program_id' => $program->id,
            'version_code' => 'BSIT-2026-DRAFT',
            'name' => 'Authoritative active curriculum',
            'state' => CurriculumVersion::StateActive,
        ]);

        try {
            app(ImportBatchLifecycleService::class)->post($batch, $registrar);
            $this->fail('An Active curriculum created after preview must block posting.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('stale_preview', $exception->errors());
        }

        $this->assertSame('Authoritative active curriculum', $active->fresh()->name);
        $this->assertSame(0, CurriculumEntry::query()->where('curriculum_version_id', $active->id)->count());
        $this->assertSame(ImportBatch::StatePendingReview, $batch->fresh()->state);
    }

    #[Test]
    public function course_specification_import_never_changes_an_existing_course_identity_state(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $course = Course::factory()->create(['code' => 'IT501', 'state' => Course::StateRetired]);
        $path = AcademicImportService::CourseSpecificationDirectory.'/preserve-course-state.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow([
                'course_code' => $course->code,
                'course_state' => Course::StateActive,
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
        app(ImportBatchLifecycleService::class)->post($batch, $registrar);

        $this->assertSame(Course::StateRetired, $course->fresh()->state);
        $this->assertDatabaseHas('course_specifications', [
            'course_id' => $course->id,
            'revision_code' => '2026-DRAFT',
            'state' => CourseSpecification::StateDraft,
        ]);
    }

    #[Test]
    public function curriculum_import_proposes_a_draft_revision_from_active_source_without_mutating_history(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'BSIT']);
        Course::factory()->create(['code' => 'IT100']);
        Course::factory()->create(['code' => 'IT102']);
        $course = Course::factory()->create(['code' => 'IT601']);
        $active = CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'revision_code' => '2025-ACTIVE',
            'title' => 'Legacy Computing',
            'credit_units' => '3.00',
            'state' => CourseSpecification::StateActive,
        ]);
        $active->components()->create([
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => '3.00',
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
            'required_room_feature_keys' => [],
            'requires_consecutive_block' => false,
            'same_faculty' => true,
            'sequence' => 1,
        ]);
        $path = AcademicImportService::CurriculumDirectory.'/proposed-draft-spec.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CurriculumImportTemplate::headers(),
            $this->validCurriculumRow([
                'program_code' => $program->code,
                'course_code' => $course->code,
                'course_revision_code' => '2026-DRAFT',
                'course_title' => 'Modern Computing',
                'course_units' => '4.00',
                'prerequisite_course_codes' => 'IT100 or IT102',
                'client_total_units' => '4.00',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCurriculum, $path, $registrar);

        $this->assertSame(0, $batch->error_count);
        $this->assertGreaterThanOrEqual(1, $batch->warning_count);

        $acknowledged = app(ImportBatchLifecycleService::class)->acknowledgeWarnings($batch, $registrar);
        app(ImportBatchLifecycleService::class)->post($acknowledged, $registrar);

        $draft = CourseSpecification::query()
            ->where('course_id', $course->id)
            ->where('revision_code', '2026-DRAFT')
            ->firstOrFail();

        $this->assertSame(CourseSpecification::StateActive, $active->fresh()->state);
        $this->assertSame('Legacy Computing', $active->fresh()->title);
        $this->assertSame(CourseSpecification::StateDraft, $draft->state);
        $this->assertSame('Modern Computing', $draft->title);
        $this->assertSame('4.00', $draft->credit_units);
        $this->assertSame(1, $draft->components()->count());
        $this->assertSame(2, $draft->requirements()->count());
        $this->assertSame(1, $draft->requirements()->distinct()->count('group_key'));
        $this->assertDatabaseHas('curriculum_entries', ['course_specification_id' => $draft->id]);
    }

    #[Test]
    public function curriculum_import_blocks_incomplete_course_sources_without_a_complete_revision_to_clone(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $program = Program::factory()->create(['code' => 'BSIT']);
        Course::factory()->create(['code' => 'IT701']);
        $path = AcademicImportService::CurriculumDirectory.'/incomplete-course-source.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CurriculumImportTemplate::headers(),
            $this->validCurriculumRow([
                'program_code' => $program->code,
                'course_code' => 'IT701',
                'course_title' => 'Incomplete Source Course',
                'course_units' => '3.00',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCurriculum, $path, $registrar);
        $details = $batch->getAttribute('validation_details');

        $this->assertIsArray($details);
        $this->assertGreaterThanOrEqual(1, $batch->error_count);
        $this->assertStringContainsString(
            'Course Specification template',
            collect($details['errors'])->pluck('message')->implode(' '),
        );
    }

    #[Test]
    public function registrar_manages_imports_while_academic_head_is_read_only(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $academicHead = $this->staff(User::StaffRoleAcademicHead);
        $faculty = $this->staff(User::StaffRoleFaculty);
        $path = AcademicImportService::CourseSpecificationDirectory.'/auth.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow(),
        ]));

        $this->actingAs($registrar);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertContains(ImportBatchResource::class, Filament::getPanel('admin')->getResources());
        $this->assertTrue(ImportBatchResource::canAccess());
        $this->assertFalse(ImportBatchResource::canCreate());
        Livewire::test(ListImportBatches::class)
            ->assertActionVisible('downloadCourseSpecificationTemplate')
            ->assertActionVisible('downloadCurriculumTemplate')
            ->assertActionVisible('uploadCourseSpecificationImport')
            ->assertActionVisible('uploadCurriculumImport');

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
        $this->assertTrue($registrar->can('update', $batch));
        $this->assertTrue($registrar->can('manage', ImportBatch::class));
        $this->assertTrue($registrar->can('download', $batch));
        Livewire::test(ListImportBatches::class)
            ->assertActionVisible(TestAction::make('downloadImportSource')->table($batch));

        $this->actingAs($academicHead);
        $this->assertTrue(ImportBatchResource::canAccess());
        $this->assertTrue($academicHead->can('view', $batch));
        $this->assertFalse($academicHead->can('update', $batch));
        $this->assertFalse($academicHead->can('manage', ImportBatch::class));
        $this->assertTrue($academicHead->can('download', $batch));
        Livewire::test(ListImportBatches::class)
            ->assertActionHidden('downloadCourseSpecificationTemplate')
            ->assertActionHidden('downloadCurriculumTemplate')
            ->assertActionHidden('uploadCourseSpecificationImport')
            ->assertActionHidden('uploadCurriculumImport')
            ->assertActionVisible(TestAction::make('downloadImportSource')->table($batch));

        $this->actingAs($faculty);
        $this->assertFalse(ImportBatchResource::canAccess());
        $this->assertFalse($faculty->can('download', $batch));

        $this->expectException(AuthorizationException::class);
        app(ImportBatchLifecycleService::class)->post($batch, $academicHead);
    }

    #[Test]
    public function non_utf8_encoded_uploads_are_rejected_before_domain_rows_are_validated(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $path = AcademicImportService::CourseSpecificationDirectory.'/latin1-encoded.csv';
        $headerLine = implode(',', CourseSpecificationImportTemplate::headers());
        // "Introducción" encoded as Latin-1/ISO-8859-1 is not valid UTF-8 (the
        // "ó" byte 0xF3 is not a legal UTF-8 continuation of any lead byte here).
        $latin1Row = implode(',', $this->validCourseSpecificationRow(['title' => "Introducci\xF3n"]));

        Storage::disk(AcademicImportService::Disk)->put($path, $headerLine."\n".$latin1Row."\n");

        $this->assertFalse(mb_check_encoding(Storage::disk(AcademicImportService::Disk)->get($path), 'UTF-8'));

        try {
            app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
            $this->fail('Non-UTF-8 encoded uploads must be rejected before domain rows are validated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
            $this->assertSame(['CSV imports must be encoded as UTF-8.'], $exception->errors()['file']);
        }

        $this->assertSame(0, ImportBatch::query()->count());
    }

    #[Test]
    public function import_batch_lifecycle_actions_write_activity_log_rows_visible_to_super_admin(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $superAdmin = $this->staff(User::StaffRoleSystemSuperAdmin);
        $path = AcademicImportService::CourseSpecificationDirectory.'/activity-log.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow([
                'credit_units' => '4.00',
                'weekly_contact_hours' => '3.00',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
        $acknowledged = app(ImportBatchLifecycleService::class)->acknowledgeWarnings($batch, $registrar);
        $posted = app(ImportBatchLifecycleService::class)->post($acknowledged, $registrar);
        $cancelBatch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);
        app(ImportBatchLifecycleService::class)->cancel($cancelBatch, $registrar);

        foreach ([
            'import_batch_preview_created' => $batch->id,
            'import_batch_warnings_acknowledged' => $acknowledged->id,
            'import_batch_posted' => $posted->id,
            'import_batch_cancelled' => $cancelBatch->id,
        ] as $event => $importBatchId) {
            $activity = Activity::query()
                ->where('log_name', 'imports')
                ->where('event', $event)
                ->where('causer_id', $registrar->id)
                ->get()
                ->sole(fn (Activity $candidate): bool => data_get($candidate->properties, 'import_batch_id') === $importBatchId);

            $this->assertSame(User::class, $activity->causer_type);
            $this->assertNull($activity->subject_id);
            $this->assertSame($importBatchId, data_get($activity->properties, 'import_batch_id'));
        }

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListActivities::class)->assertOk();
    }

    #[Test]
    public function findings_csv_download_neutralizes_formula_injection_in_source_values(): void
    {
        $registrar = $this->staff(User::StaffRoleRegistrar);
        $path = AcademicImportService::CourseSpecificationDirectory.'/formula-injection.csv';

        Storage::disk(AcademicImportService::Disk)->put($path, AcademicImportCsv::toCsv([
            CourseSpecificationImportTemplate::headers(),
            $this->validCourseSpecificationRow([
                'course_code' => 'IT901',
                'title' => '=SUM(A1:A9)',
                'credit_units' => 'bad',
            ]),
        ]));

        $batch = app(AcademicImportService::class)->createPreview(ImportBatch::TypeCourseSpecification, $path, $registrar);

        $this->assertGreaterThanOrEqual(1, $batch->error_count);

        $csv = app(AcademicImportService::class)->validationFindingsCsv($batch, $registrar);

        $this->assertStringContainsString('severity,source_row,message,source_values', $csv);
        $this->assertStringContainsString("'=SUM(A1:A9)", $csv);
        $this->assertStringNotContainsString('"title":"=SUM(A1:A9)"', $csv);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return list<string>
     */
    private function validCourseSpecificationRow(array $overrides = []): array
    {
        $row = [
            'template_version' => CourseSpecificationImportTemplate::Version,
            'template_type' => ImportBatch::TypeCourseSpecification,
            'course_code' => 'IT101',
            'course_state' => Course::StateActive,
            'revision_code' => '2026-DRAFT',
            'title' => 'Introduction to Computing',
            'description' => 'Foundational computing concepts for first-year learners.',
            'credit_units' => '3.00',
            'grading_profile_key' => CourseSpecification::GradingProfileCollegeStandard,
            'grading_profile_version' => '1',
            'allowed_modalities' => CourseSpecification::ModalityFaceToFace,
            'same_faculty_default' => 'yes',
            'effective_term_label' => '',
            'state' => CourseSpecification::StateDraft,
            'component_type' => CourseComponent::TypeLecture,
            'weekly_contact_hours' => '3.00',
            'room_type_default' => CourseComponent::RoomTypeLectureRoom,
            'required_room_feature_keys' => '',
            'modality_restriction' => '',
            'requires_consecutive_block' => 'no',
            'same_faculty' => 'yes',
            'component_sequence' => '1',
            'prerequisite_course_codes' => '',
            'corequisite_course_codes' => '',
        ];

        $row = [...$row, ...$overrides];

        return array_map(fn (string $header): string => $row[$header], CourseSpecificationImportTemplate::headers());
    }

    /**
     * @param  array<string, string>  $overrides
     * @return list<string>
     */
    private function validCurriculumRow(array $overrides = []): array
    {
        $row = [
            'template_version' => CurriculumImportTemplate::Version,
            'template_type' => ImportBatch::TypeCurriculum,
            'program_code' => 'BSIT',
            'curriculum_version_code' => 'BSIT-2026-DRAFT',
            'curriculum_name' => 'BSIT Curriculum 2026',
            'effective_entry_term_label' => '',
            'state' => CurriculumVersion::StateDraft,
            'course_code' => 'IT101',
            'course_revision_code' => '2026-DRAFT',
            'course_title' => 'Introduction to Computing',
            'course_units' => '3.00',
            'prerequisite_course_codes' => '',
            'year_level' => '1',
            'term_label' => 'First Semester',
            'term_type' => Term::TypeFirstSemester,
            'sequence' => '1',
            'requirement_group' => CurriculumEntry::RequirementGroupRequired,
            'client_total_units' => '3.00',
        ];

        $row = [...$row, ...$overrides];

        return array_map(fn (string $header): string => $row[$header], CurriculumImportTemplate::headers());
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['status' => User::StatusActive]);
        $user->assignRole($role);

        return $user;
    }

    private function courseSpecification(string $courseCode, string $revisionCode, string $creditUnits): CourseSpecification
    {
        $course = Course::factory()->create(['code' => $courseCode]);

        return CourseSpecification::factory()->create([
            'course_id' => $course->id,
            'revision_code' => $revisionCode,
            'title' => 'Introduction to Computing',
            'credit_units' => $creditUnits,
            'state' => CourseSpecification::StateDraft,
        ]);
    }
}
