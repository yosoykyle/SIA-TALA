<?php

namespace App\Filament\Resources\CourseSpecifications\Pages;

use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Models\CourseSpecification;
use Filament\Resources\Pages\CreateRecord;

class CreateCourseSpecification extends CreateRecord
{
    protected static string $resource = CourseSpecificationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'state' => CourseSpecification::StateDraft,
        ];
    }
}
