<?php

namespace App\Filament\Resources\FinancialAccommodations\Pages;

use App\Filament\Resources\FinancialAccommodations\FinancialAccommodationResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateFinancialAccommodation extends CreateRecord
{
    protected static string $resource = FinancialAccommodationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        $data['recorded_by'] = $actor->id;

        return $data;
    }
}
