<?php

namespace App\Filament\Resources\DisposalReviews\Pages;

use App\Filament\Resources\DisposalReviews\DisposalReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListDisposalReviews extends ListRecords
{
    protected static string $resource = DisposalReviewResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
