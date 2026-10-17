<?php

namespace App\Filament\Resources\Sections\Pages;

use App\Filament\Resources\Sections\SectionResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSection extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = SectionResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-class-detail'];
    }

    public function getSubheading(): string
    {
        return 'Class · '.$this->getRecord()->code;
    }

    public function getBreadcrumb(): string
    {
        return 'Class details';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit class'),
        ];
    }
}
