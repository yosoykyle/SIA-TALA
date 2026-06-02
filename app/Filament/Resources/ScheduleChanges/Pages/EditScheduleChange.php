<?php

namespace App\Filament\Resources\ScheduleChanges\Pages;

use App\Filament\Resources\ScheduleChanges\ScheduleChangeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduleChange extends EditRecord
{
    protected static string $resource = ScheduleChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
