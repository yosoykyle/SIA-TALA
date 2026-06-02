<?php

namespace App\Filament\Resources\FaqEntries\Pages;

use App\Filament\Resources\FaqEntries\FaqEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFaqEntry extends CreateRecord
{
    protected static string $resource = FaqEntryResource::class;
}
