<?php

namespace App\Filament\Resources\Sections\Pages;

use App\Filament\Resources\Sections\SectionResource;
use App\Support\HasTermPlanningContext;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSection extends ViewRecord
{
    use HasTermPlanningContext;

    protected static string $resource = SectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
