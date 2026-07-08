<?php

namespace App\Filament\Resources\StudentProfiles\Pages;

use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditStudentProfile extends EditRecord
{
    protected static string $resource = StudentProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $changedFields = collect($this->record->getChanges())
            ->keys()
            ->reject(fn (string $field): bool => $field === 'updated_at')
            ->values()
            ->all();

        if ($changedFields === []) {
            return;
        }

        activity()
            ->performedOn($this->record)
            ->causedBy(auth()->user())
            ->event('student_profile_updated')
            ->withProperties(['updated_fields' => $changedFields])
            ->log('Student profile updated (Admin Override)');
    }
}
