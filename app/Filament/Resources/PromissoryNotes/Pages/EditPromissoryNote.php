<?php

namespace App\Filament\Resources\PromissoryNotes\Pages;

use App\Filament\Resources\PromissoryNotes\PromissoryNoteResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPromissoryNote extends EditRecord
{
    protected static string $resource = PromissoryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
