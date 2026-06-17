<?php

namespace App\Filament\Resources\DeliveryPatterns\Pages;

use App\Actions\Scheduling\DeliveryPatternService;
use App\Filament\Resources\DeliveryPatterns\DeliveryPatternResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateDeliveryPattern extends CreateRecord
{
    protected static string $resource = DeliveryPatternResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();

        return app(DeliveryPatternService::class)->prepareForSave(
            $data,
            null,
            $actor instanceof User ? $actor : null,
        );
    }
}
