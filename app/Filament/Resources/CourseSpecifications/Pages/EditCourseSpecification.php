<?php

namespace App\Filament\Resources\CourseSpecifications\Pages;

use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
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
}
