<?php

namespace Tests\Feature;

use App\Actions\Imports\CurriculumImportService;
use App\Actions\Imports\CurriculumImportTemplate;
use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\ImportBatch;
use App\Models\Program;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CurriculumImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_exports_strict_curriculum_headers(): void
    {
        $this->assertSame([
            'Education Level',
            'Program Code',
            'Program Name',
            'Curriculum Version',
            'Effective Year',
            'Is Active',
            'Year/Grade',
            'Curriculum Period',
            'Subject Code',
            'Subject Title',
            'Units',
            'Weekly Contact Hours',
            'Academic Subject Type',
            'Scheduling Group',
            'Delivery Rule Override',
            'Category',
            'Sort Order',
        ], CurriculumImportTemplate::headers());

        $csv = CurriculumImportTemplate::csv();

        $this->assertStringContainsString('"Education Level","Program Code","Program Name","Curriculum Version"', $csv);
        $this->assertStringContainsString('college,BSIT,"Bachelor of Science in Information Technology","BSIT 2026"', $csv);
    }

    public function test_create_preview_stores_pending_batch_with_valid_rows_and_private_file_reference(): void
    {
        Storage::fake('local');
        $registrar = $this->registrar();
        $path = $this->storeCsv('imports/curriculum/uploads/valid.csv', $this->validCsv());

        $batch = app(CurriculumImportService::class)->createPreview($path, 'valid.csv', $registrar);

        $this->assertSame(ImportBatch::TypeCurriculum, $batch->import_type);
        $this->assertSame('valid.csv', $batch->file_name);
        $this->assertSame($path, $batch->file_path);
        $this->assertSame(ImportBatch::StatusPendingReview, $batch->status);
        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, $batch->valid_rows);
        $this->assertSame(0, $batch->error_rows);
        $this->assertSame($registrar->id, $batch->imported_by);
        $this->assertSame('curriculum_preview_v2', $batch->error_log['schema']);
        $this->assertCount(2, $batch->error_log['valid_rows']);
        Storage::disk('local')->assertExists($path);
    }

    public function test_create_preview_records_validation_errors_without_creating_foundation_records(): void
    {
        Storage::fake('local');
        $registrar = $this->registrar();
        $path = $this->storeCsv('imports/curriculum/uploads/invalid.csv', implode("\n", [
            implode(',', CurriculumImportTemplate::headers()),
            'college,BSIT,Bachelor of Science in Information Technology,BSIT 2026,2026,yes,1st Year,1st Semester,IT101,,3.00,3.00,major,lecture,,lecture,1',
            'college,BSIT,Bachelor of Science in Information Technology,BSIT 2026,2026,yes,1st Year,1st Semester,MATH101,College Algebra,not-a-number,3.00,minor,lecture,,lecture,2',
        ]));

        $batch = app(CurriculumImportService::class)->createPreview($path, 'invalid.csv', $registrar);

        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(0, $batch->valid_rows);
        $this->assertSame(2, $batch->error_rows);
        $this->assertSame([], $batch->error_log['valid_rows']);
        $this->assertSame(2, count($batch->error_log['errors']));
        $this->assertSame(0, Program::query()->count());
        $this->assertSame(0, Subject::query()->count());
    }

    public function test_commit_persists_program_subject_curriculum_and_curriculum_subjects_with_audit(): void
    {
        Storage::fake('local');
        $registrar = $this->registrar();
        $path = $this->storeCsv('imports/curriculum/uploads/valid.csv', $this->validCsv());
        $batch = app(CurriculumImportService::class)->createPreview($path, 'valid.csv', $registrar);

        $committed = app(CurriculumImportService::class)->commit($batch, $registrar);

        $this->assertSame(ImportBatch::StatusCommitted, $committed->status);
        $this->assertSame($registrar->id, $committed->committed_by);
        $this->assertNotNull($committed->committed_at);
        $this->assertSame(1, Program::query()->where('code', 'BSIT')->count());
        $this->assertSame(2, Subject::query()->count());
        $this->assertSame(1, Curriculum::query()->count());
        $this->assertSame(2, CurriculumSubject::query()->count());
        $this->assertSame(1, CurriculumReadinessScope::query()->count());

        $this->assertDatabaseHas('subjects', [
            'code' => 'IT101',
            'description' => 'Introduction to Computing',
            'department' => 'college',
            'category' => 'lecture',
        ]);
        $this->assertDatabaseHas('curriculum_subjects', [
            'year_level' => '1st Year',
            'semester' => '1st Semester',
            'weekly_contact_hours' => '3.00',
            'academic_subject_type' => 'major',
            'scheduling_group' => 'lecture',
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('curriculum_readiness_scopes', [
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'status' => CurriculumReadinessScope::StatusNeedsReview,
            'last_transition_by' => $registrar->id,
        ]);

        $properties = $this->activityProperties('import_batch_committed');

        $this->assertSame($batch->id, $properties['import_batch_id']);
        $this->assertSame(2, $properties['committed_rows']);
        $this->assertSame(ImportBatch::TypeCurriculum, $properties['import_type']);
        $this->assertSame(1, $properties['readiness_scopes_touched']);
    }

    public function test_commit_rejects_batches_with_preview_errors_or_unsupported_type(): void
    {
        Storage::fake('local');
        $registrar = $this->registrar();
        $invalidPath = $this->storeCsv('imports/curriculum/uploads/invalid.csv', implode("\n", [
            implode(',', CurriculumImportTemplate::headers()),
            'college,BSIT,Bachelor of Science in Information Technology,BSIT 2026,2026,yes,1st Year,1st Semester,IT101,,3.00,3.00,major,lecture,,lecture,1',
        ]));
        $invalidBatch = app(CurriculumImportService::class)->createPreview($invalidPath, 'invalid.csv', $registrar);

        try {
            app(CurriculumImportService::class)->commit($invalidBatch, $registrar);
            $this->fail('Expected invalid import batch to fail commit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('error_rows', $exception->errors());
            $this->assertSame(ImportBatch::StatusPendingReview, $invalidBatch->refresh()->status);
        }

        $unsupportedBatch = ImportBatch::query()->create([
            'import_type' => ImportBatch::TypeStudentData,
            'file_name' => 'students.csv',
            'file_path' => 'imports/curriculum/uploads/students.csv',
            'total_rows' => 1,
            'valid_rows' => 1,
            'error_rows' => 0,
            'skipped_rows' => 0,
            'status' => ImportBatch::StatusPendingReview,
            'imported_by' => $registrar->id,
            'error_log' => [],
        ]);

        try {
            app(CurriculumImportService::class)->commit($unsupportedBatch, $registrar);
            $this->fail('Expected unsupported import batch to fail commit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('import_type', $exception->errors());
        }
    }

    public function test_commit_rejects_zero_valid_row_preview_even_when_there_are_no_row_errors(): void
    {
        Storage::fake('local');
        $registrar = $this->registrar();
        $path = $this->storeCsv('imports/curriculum/uploads/empty.csv', implode("\n", [
            implode(',', CurriculumImportTemplate::headers()),
            ',,,,,,,,,,,,,,,,',
        ]));
        $batch = app(CurriculumImportService::class)->createPreview($path, 'empty.csv', $registrar);

        $this->assertSame(0, $batch->valid_rows);
        $this->assertSame(0, $batch->error_rows);
        $this->assertSame(1, $batch->skipped_rows);

        try {
            app(CurriculumImportService::class)->commit($batch, $registrar);
            $this->fail('Expected zero-valid-row import batch to fail commit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('valid_rows', $exception->errors());
        }
    }

    public function test_import_actions_require_curriculum_management_permission(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create();
        $path = $this->storeCsv('imports/curriculum/uploads/valid.csv', $this->validCsv());

        $this->expectException(AuthorizationException::class);

        app(CurriculumImportService::class)->createPreview($path, 'valid.csv', $actor);
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-curricula'));

        return $registrar;
    }

    private function storeCsv(string $path, string $contents): string
    {
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    private function validCsv(): string
    {
        return implode("\n", [
            implode(',', CurriculumImportTemplate::headers()),
            'college,BSIT,Bachelor of Science in Information Technology,BSIT 2026,2026,yes,1st Year,1st Semester,IT101,Introduction to Computing,3.00,3.00,major,lecture,,lecture,1',
            'college,BSIT,Bachelor of Science in Information Technology,BSIT 2026,2026,yes,1st Year,1st Semester,MATH101,College Algebra,3.00,3.00,minor,lecture,,lecture,2',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityProperties(string $event): array
    {
        $activity = DB::table('activity_log')
            ->where('subject_type', ImportBatch::class)
            ->where('event', $event)
            ->first();

        $this->assertNotNull($activity);

        return json_decode((string) $activity->properties, true, 512, JSON_THROW_ON_ERROR);
    }
}
