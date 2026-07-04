<?php

namespace App\Filament\Resources\CourseSpecifications\Pages;

use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourseSpecifications extends ListRecords
{
    protected static string $resource = CourseSpecificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
