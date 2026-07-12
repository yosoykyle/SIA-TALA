<?php

namespace App\Filament\Resources\FinancialAccommodations\Pages;

use App\Actions\Finance\FinancialAccommodationLifecycleService;
use App\Filament\Resources\FinancialAccommodations\FinancialAccommodationResource;
use App\Models\FinancialAccommodation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use LogicException;

class ViewFinancialAccommodation extends ViewRecord
{
    protected static string $resource = FinancialAccommodationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('transitionStatus')
                ->label('Update Status')
                ->authorize(fn (): bool => Gate::allows('transition', $this->financialAccommodation()))
                ->visible(fn (): bool => $this->financialAccommodation()->transitionStatusOptions() !== [])
                ->schema([
                    Select::make('status')
                        ->label('New Status')
                        ->options(fn (): array => $this->financialAccommodation()->transitionStatusOptions())
                        ->required(),
                    Textarea::make('reason')
                        ->required()
                        ->maxLength(FinancialAccommodationLifecycleService::ReasonMaxLength),
                ])
                ->action(function (array $data, FinancialAccommodationLifecycleService $service): void {
                    $actor = auth()->user();

                    abort_unless($actor instanceof User, 403);

                    $this->record = $service->transition(
                        $this->financialAccommodation(),
                        (string) $data['status'],
                        (string) $data['reason'],
                        $actor,
                    );

                    Notification::make()
                        ->success()
                        ->title('Financial accommodation status updated')
                        ->send();
                }),
        ];
    }

    private function financialAccommodation(): FinancialAccommodation
    {
        $record = $this->getRecord();

        if (! $record instanceof FinancialAccommodation) {
            throw new LogicException('The Financial Accommodation page received an unexpected record type.');
        }

        return $record;
    }
}
