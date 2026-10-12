<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\Rooms\RoomResource;
use App\Support\HasTermPlanningContext;
use Filament\Resources\Pages\CreateRecord;

class CreateRoom extends CreateRecord
{
    use HasTermPlanningContext;

    protected static string $resource = RoomResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
