<?php

namespace App\Filament\Resources\FacultySubjectEligibilities\Pages;

use App\Filament\Resources\FacultySubjectEligibilities\FacultySubjectEligibilityResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

class EditFacultySubjectEligibility extends EditRecord
{
    protected static string $resource = FacultySubjectEligibilityResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $actor = auth()->user();

        if ($actor instanceof User) {
            $data['approved_by'] = $actor->id;
            $data['approved_at'] = now();
        }

        return $data;
    }
}
