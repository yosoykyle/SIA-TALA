<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\CurriculumVersion;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCurriculumVersion extends EditRecord
{
    protected static string $resource = CurriculumVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (! $record instanceof CurriculumVersion) {
            return [
                ...$data,
                'state' => CurriculumVersion::StateDraft,
                'approval_reference' => null,
                'approved_by' => null,
                'approved_at' => null,
            ];
        }

        return [
            ...$data,
            'state' => $record->state,
            'approval_reference' => $record->approval_reference,
            'approved_by' => $record->approved_by,
            'approved_at' => $record->approved_at,
        ];
    }
}
