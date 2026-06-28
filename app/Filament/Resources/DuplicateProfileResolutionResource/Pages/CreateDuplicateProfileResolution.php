<?php

namespace App\Filament\Resources\DuplicateProfileResolutionResource\Pages;

use App\Actions\Enrollment\DuplicateProfileResolver;
use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Models\StudentProfile;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDuplicateProfileResolution extends CreateRecord
{
    protected static string $resource = DuplicateProfileResolutionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $duplicate = StudentProfile::withDuplicates()->findOrFail($data['duplicate_student_id']);
        $primary = StudentProfile::withDuplicates()->findOrFail($data['primary_student_id']);

        return app(DuplicateProfileResolver::class)->resolve(
            $duplicate,
            $primary,
            $data['resolution_type'],
            $data['reason'],
            auth()->user()
        );
    }
}
