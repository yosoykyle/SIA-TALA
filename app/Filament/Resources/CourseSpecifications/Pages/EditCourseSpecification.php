<?php

namespace App\Filament\Resources\CourseSpecifications\Pages;

use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Models\CourseSpecification;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseSpecification extends EditRecord
{
    protected static string $resource = CourseSpecificationResource::class;

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

        return [
            ...$data,
            'state' => $record instanceof CourseSpecification
                ? $record->state
                : CourseSpecification::StateDraft,
        ];
    }
}
