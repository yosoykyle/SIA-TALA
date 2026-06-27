<?php

namespace App\Filament\Resources\ApplicantIntakes\Pages;

use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use Filament\Resources\Pages\ListRecords;

class ListApplicantIntakes extends ListRecords
{
    protected static string $resource = ApplicantIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
