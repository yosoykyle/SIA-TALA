<?php

namespace App\Filament\Resources\PromissoryNotes\Pages;

use App\Filament\Resources\PromissoryNotes\PromissoryNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromissoryNotes extends ListRecords
{
    protected static string $resource = PromissoryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
