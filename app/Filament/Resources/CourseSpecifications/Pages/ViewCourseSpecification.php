<?php

namespace App\Filament\Resources\CourseSpecifications\Pages;

use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCourseSpecification extends ViewRecord
{
    protected static string $resource = CourseSpecificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
