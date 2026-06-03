<?php

namespace App\Filament\Resources\LedgerEntries\Pages;

use App\Filament\Resources\LedgerEntries\LedgerEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;
}
