<?php

namespace App\Filament\Resources\FacultySubjectEligibilities\Pages;

use App\Filament\Resources\FacultySubjectEligibilities\FacultySubjectEligibilityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFacultySubjectEligibilities extends ListRecords
{
    protected static string $resource = FacultySubjectEligibilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => auth()->user()?->can('create', static::getModel()) ?? false),
        ];
    }
}
