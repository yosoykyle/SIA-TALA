<?php

namespace App\Actions\Imports;

use App\Models\ImportBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportBatchLifecycleService
{
    public function commit(ImportBatch $importBatch, User $actor): ImportBatch
    {
        $this->authorizeRegistrarImportAction($actor);

        if ($importBatch->import_type === ImportBatch::TypeCurriculum) {
            return app(CurriculumImportService::class)->commit($importBatch, $actor);
        }

        throw ValidationException::withMessages([
            'import_type' => 'Only curriculum imports have a controlled TAL-12 commit pipeline.',
        ]);
    }

    public function cancel(ImportBatch $importBatch, User $actor): ImportBatch
    {
        return $this->transition(
            importBatch: $importBatch,
            actor: $actor,
            status: ImportBatch::StatusCancelled,
            event: 'import_batch_cancelled',
        );
    }

    private function transition(
        ImportBatch $importBatch,
        User $actor,
        string $status,
        string $event,
    ): ImportBatch {
        $this->authorizeRegistrarImportAction($actor);

        return DB::transaction(function () use ($importBatch, $actor, $status, $event): ImportBatch {
            $locked = ImportBatch::query()
                ->lockForUpdate()
                ->findOrFail($importBatch->getKey());

            if (! $locked->isPendingReview()) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending import batches can be committed or cancelled.',
                ]);
            }

            $timestamp = CarbonImmutable::now(config('app.timezone'));

            $locked->forceFill([
                'status' => $status,
                'committed_by' => $status === ImportBatch::StatusCommitted ? $actor->id : $locked->committed_by,
                'committed_at' => $status === ImportBatch::StatusCommitted ? $timestamp : $locked->committed_at,
            ])->save();

            DB::table('activity_log')->insert([
                'log_name' => 'imports',
                'description' => 'Import batch state changed.',
                'subject_type' => ImportBatch::class,
                'subject_id' => null,
                'event' => $event,
                'causer_type' => User::class,
                'causer_id' => $actor->id,
                'properties' => json_encode([
                    'import_batch_id' => $locked->id,
                    'status_after' => $status,
                ], JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp->toDateTimeString(),
                'updated_at' => $timestamp->toDateTimeString(),
            ]);

            return $locked->fresh();
        });
    }

    private function authorizeRegistrarImportAction(User $actor): void
    {
        foreach (['manage-curricula', 'manage-schedules', 'evaluate-transferees'] as $permission) {
            if ($actor->can($permission)) {
                return;
            }
        }

        throw new AuthorizationException('Only authorized Registrar staff can manage import batches.');
    }
}
