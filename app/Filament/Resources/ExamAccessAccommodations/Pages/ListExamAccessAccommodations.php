<?php

namespace App\Filament\Resources\ExamAccessAccommodations\Pages;

use App\Filament\Resources\ExamAccessAccommodations\ExamAccessAccommodationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExamAccessAccommodations extends ListRecords
{
    protected static string $resource = ExamAccessAccommodationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
