<?php

namespace App\Filament\Resources\PromissoryNotes\Pages;

use App\Filament\Resources\PromissoryNotes\PromissoryNoteResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPromissoryNote extends ViewRecord
{
    protected static string $resource = PromissoryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
