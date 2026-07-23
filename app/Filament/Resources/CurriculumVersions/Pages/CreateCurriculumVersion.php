<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\CurriculumVersion;
use Filament\Resources\Pages\CreateRecord;

class CreateCurriculumVersion extends CreateRecord
{
    protected static string $resource = CurriculumVersionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'state' => CurriculumVersion::StateDraft,
            'approval_reference' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }
}
