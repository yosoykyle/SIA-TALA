<?php

namespace App\Filament\Resources\DuplicateProfileResolutionResource\Pages;

use App\Actions\Enrollment\DuplicateProfileResolver;
use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateDuplicateProfileResolution extends CreateRecord
{
    protected static string $resource = DuplicateProfileResolutionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $duplicate = StudentProfile::query()->findOrFail($data['duplicate_student_profile_id']);
        $primary = StudentProfile::query()->findOrFail($data['primary_student_profile_id']);
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new AuthorizationException('Authentication is required to resolve duplicate student profiles.');
        }

        return app(DuplicateProfileResolver::class)->resolve(
            $duplicate,
            $primary,
            $data['resolution_type'],
            $data['reason'],
            $actor,
        );
    }
}
