<?php

namespace App\Filament\Resources\Terms\Pages;

use App\Filament\Resources\Terms\TermResource;
use App\Support\ReturnsToTermPlanning;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTerms extends ListRecords
{
    use ReturnsToTermPlanning {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = TermResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-terms-list'];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Create Term')->icon('heroicon-o-plus'),
        ];
    }
}
