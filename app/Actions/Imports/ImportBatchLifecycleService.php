<?php

namespace App\Actions\Imports;

use App\Models\ImportBatch;
use App\Models\User;

class ImportBatchLifecycleService
{
    public function __construct(private readonly AcademicImportService $academicImportService) {}

    public function acknowledgeWarnings(ImportBatch $importBatch, User $actor): ImportBatch
    {
        return $this->academicImportService->acknowledgeWarnings($importBatch, $actor);
    }

    public function post(ImportBatch $importBatch, User $actor): ImportBatch
    {
        return $this->academicImportService->post($importBatch, $actor);
    }

    public function commit(ImportBatch $importBatch, User $actor): ImportBatch
    {
        return $this->post($importBatch, $actor);
    }

    public function cancel(ImportBatch $importBatch, User $actor): ImportBatch
    {
        return $this->academicImportService->cancel($importBatch, $actor);
    }
}
