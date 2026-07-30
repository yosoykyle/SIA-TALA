<?php

namespace App\Filament\Resources\GraduationReviewBatches\Pages;

use App\Actions\Graduation\CloseGraduationReviewBatch;
use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use App\Models\GraduationReviewBatch;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewGraduationReviewBatch extends ViewRecord
{
    protected static string $resource = GraduationReviewBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->label('Review students')
                ->visible(fn (GraduationReviewBatch $record): bool => $record->state === GraduationReviewBatch::StateOpen),
            Action::make('closeReview')
                ->label('Close review')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Close this completion eligibility review?')
                ->modalDescription('Closing locks the review list and its student visibility actions. Existing snapshots remain available for authorized review.')
                ->modalSubmitActionLabel('Close review')
                ->authorize('update')
                ->visible(fn (GraduationReviewBatch $record): bool => $record->state === GraduationReviewBatch::StateOpen)
                ->action(function (GraduationReviewBatch $record): void {
                    /** @var User $actor */
                    $actor = auth()->user();

                    app(CloseGraduationReviewBatch::class)->execute($record, $actor);
                    $record->refresh();
                    $this->refreshFormData(['state', 'closed_at']);

                    Notification::make()
                        ->title('Completion review closed')
                        ->success()
                        ->send();
                }),
        ];
    }
}
