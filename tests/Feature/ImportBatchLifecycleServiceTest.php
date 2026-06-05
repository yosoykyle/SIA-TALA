<?php

namespace Tests\Feature;

use App\Actions\Imports\ImportBatchLifecycleService;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImportBatchLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_commit_records_committer_status_and_lifecycle_activity(): void
    {
        $registrar = $this->registrar();
        $batch = $this->importBatch();

        $result = app(ImportBatchLifecycleService::class)->commit($batch, $registrar);

        $batch->refresh();
        $properties = $this->activityProperties('import_batch_committed');

        $this->assertTrue($result->is($batch));
        $this->assertSame(ImportBatch::StatusCommitted, $batch->status);
        $this->assertSame($registrar->id, $batch->committed_by);
        $this->assertNotNull($batch->committed_at);
        $this->assertSame($batch->id, $properties['import_batch_id']);
        $this->assertSame(ImportBatch::StatusCommitted, $properties['status_after']);
    }

    public function test_cancel_records_cancelled_status_without_commit_metadata(): void
    {
        $registrar = $this->registrar();
        $batch = $this->importBatch();

        app(ImportBatchLifecycleService::class)->cancel($batch, $registrar);

        $batch->refresh();
        $properties = $this->activityProperties('import_batch_cancelled');

        $this->assertSame(ImportBatch::StatusCancelled, $batch->status);
        $this->assertNull($batch->committed_by);
        $this->assertNull($batch->committed_at);
        $this->assertSame($batch->id, $properties['import_batch_id']);
        $this->assertSame(ImportBatch::StatusCancelled, $properties['status_after']);
    }

    public function test_import_batch_transition_requires_registrar_import_permission(): void
    {
        $actor = User::factory()->create();
        $batch = $this->importBatch();

        try {
            app(ImportBatchLifecycleService::class)->commit($batch, $actor);
            $this->fail('Expected import batch commit to require Registrar import permissions.');
        } catch (AuthorizationException) {
            $this->assertSame(ImportBatch::StatusPendingReview, $batch->refresh()->status);
        }
    }

    public function test_import_batch_options_match_database_enum_contract(): void
    {
        $this->assertSame([
            ImportBatch::TypeStudentData => 'Student Data',
            ImportBatch::TypeLegacyGrades => 'Legacy Grades',
            ImportBatch::TypeLegacyFinancial => 'Legacy Financial',
            ImportBatch::TypeEnrollmentRecords => 'Enrollment Records',
            ImportBatch::TypeCurriculum => 'Curriculum',
        ], ImportBatch::importTypeOptions());

        $this->assertSame([
            ImportBatch::StatusPendingReview => 'Pending Review',
            ImportBatch::StatusCommitted => 'Committed',
            ImportBatch::StatusCancelled => 'Cancelled',
        ], ImportBatch::statusOptions());
    }

    public function test_non_pending_import_batch_cannot_transition(): void
    {
        $registrar = $this->registrar();
        $batch = $this->importBatch([
            'status' => ImportBatch::StatusCommitted,
            'committed_by' => $registrar->id,
            'committed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(ImportBatchLifecycleService::class)->cancel($batch, $registrar);
    }

    private function registrar(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $registrar = User::factory()->create();
        $registrar->givePermissionTo(Permission::findOrCreate('manage-curricula'));

        return $registrar;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function importBatch(array $attributes = []): ImportBatch
    {
        return ImportBatch::query()->create([
            'import_type' => ImportBatch::TypeStudentData,
            'file_name' => 'legacy-students.xlsx',
            'file_path' => 'imports/legacy-students.xlsx',
            'total_rows' => 10,
            'valid_rows' => 9,
            'error_rows' => 1,
            'skipped_rows' => 0,
            'status' => ImportBatch::StatusPendingReview,
            'imported_by' => User::factory()->create()->id,
            'error_log' => [],
            ...$attributes,
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
