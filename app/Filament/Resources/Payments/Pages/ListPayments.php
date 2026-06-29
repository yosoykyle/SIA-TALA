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
            'verified' => Tab::make('Verified Payments')
                ->modifyQueryUsing(fn ($query) => $query->where('evidence_status', 'verified')),
            'pending_or_mapping' => Tab::make('Pending OR Mapping')
                ->modifyQueryUsing(fn ($query) => $query->where('evidence_status', 'verified')->whereNull('or_number')),
            'under_review' => Tab::make('Under Review')
                ->modifyQueryUsing(fn ($query) => $query->where('evidence_status', 'under_review')),
        ];
    }
}
