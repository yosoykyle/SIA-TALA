<?php

namespace App\Filament\Resources\PersonalDataCorrectionRequestResource\Pages;

use App\Actions\Enrollment\PersonalDataCorrectionService;
use App\Filament\Resources\PersonalDataCorrectionRequestResource;
use App\Models\PersonalDataCorrectionRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPersonalDataCorrectionRequest extends ViewRecord
{
    protected static string $resource = PersonalDataCorrectionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->status === PersonalDataCorrectionRequest::STATUS_PENDING
                )
                ->action(function (): void {
                    $record = $this->getRecord();
                    try {
                        app(PersonalDataCorrectionService::class)->resolveRequest($record, auth()->user(), 'approve');

                        Notification::make()
                            ->title('Request approved successfully')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Approval failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->icon(Heroicon::OutlinedXCircle)
                ->schema([
                    Textarea::make('reject_reason')
                        ->label('Reason for Rejection')
                        ->required()
                        ->maxLength(500),
                ])
                ->visible(fn (): bool => $this->getRecord()->status === PersonalDataCorrectionRequest::STATUS_PENDING
                )
                ->action(function (array $data): void {
                    $record = $this->getRecord();
                    try {
                        app(PersonalDataCorrectionService::class)->resolveRequest($record, auth()->user(), 'reject', $data['reject_reason']);

                        Notification::make()
                            ->title('Request rejected')
                            ->danger()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Rejection failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
