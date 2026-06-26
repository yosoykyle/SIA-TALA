<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    public function getTabs(): array
    {
        return [
            'confirmed' => Tab::make('Confirmed Payments')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'confirmed')),
            'pending_or_mapping' => Tab::make('Pending OR Mapping')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'confirmed')->whereNull('or_number')),
            'voided' => Tab::make('Voided')
                ->modifyQueryUsing(fn ($query) => $query->where('status', 'voided')),
        ];
    }
}
