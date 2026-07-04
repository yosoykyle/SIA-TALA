<?php

namespace App\Actions\Imports;

use App\Models\ImportBatch;
use App\Models\User;

class CourseSpecificationImportService
{
    public function __construct(private readonly AcademicImportService $academicImportService) {}

    /**
     * @return array{directory:string, accepted_file_types:list<string>, max_size_kb:int}
     */
    public static function uploadContract(): array
    {
        return AcademicImportService::uploadContract(ImportBatch::TypeCourseSpecification);
    }

    public function createPreview(string $filePath, string $fileName, User $actor): ImportBatch
    {
        return $this->academicImportService->createPreview(ImportBatch::TypeCourseSpecification, $filePath, $actor);
    }
}
