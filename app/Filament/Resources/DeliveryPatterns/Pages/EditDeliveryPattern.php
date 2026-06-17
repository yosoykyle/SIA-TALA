<?php

namespace App\Filament\Resources\DeliveryPatterns\Pages;

use App\Actions\Scheduling\DeliveryPatternService;
use App\Filament\Resources\DeliveryPatterns\DeliveryPatternResource;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditDeliveryPattern extends EditRecord
{
    protected static string $resource = DeliveryPatternResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $actor = auth()->user();

        return app(DeliveryPatternService::class)->prepareForSave(
            $data,
            $this->record,
            $actor instanceof User ? $actor : null,
        );
    }
}
