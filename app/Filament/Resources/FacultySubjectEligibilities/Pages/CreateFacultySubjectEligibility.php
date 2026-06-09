<?php

namespace App\Filament\Resources\FacultySubjectEligibilities\Pages;

use App\Filament\Resources\FacultySubjectEligibilities\FacultySubjectEligibilityResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateFacultySubjectEligibility extends CreateRecord
{
    protected static string $resource = FacultySubjectEligibilityResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();

        if ($actor instanceof User) {
            $data['approved_by'] = $actor->id;
            $data['approved_at'] = now();
        }

        return $data;
    }
}
