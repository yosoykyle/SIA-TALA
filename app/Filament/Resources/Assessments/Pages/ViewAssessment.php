<?php

namespace App\Filament\Resources\Assessments\Pages;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Filament\Resources\Assessments\AssessmentResource;
use App\Models\Assessment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewAssessment extends ViewRecord
{
    protected static string $resource = AssessmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activateAssessment')
                ->label('Activate Assessment')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->canActivate())
                ->action(function (): void {
                    $actor = auth()->user();
                    $assessment = $this->getRecord();

                    if (! $actor instanceof User || ! $assessment instanceof Assessment) {
                        abort(403);
                    }

                    $this->record = app(EnrollmentAssessmentService::class)->activate($assessment, $actor);

                    Notification::make()
                        ->title('Assessment activated')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function canActivate(): bool
    {
        $user = auth()->user();
        $assessment = $this->getRecord();

        return $user instanceof User
            && $assessment instanceof Assessment
            && $assessment->state === Assessment::StateDraft
            && $user->can('activate', $assessment);
    }
}
