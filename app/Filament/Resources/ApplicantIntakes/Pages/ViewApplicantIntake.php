<?php

namespace App\Filament\Resources\ApplicantIntakes\Pages;

use App\Actions\Applicants\ApplicantReviewService;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Models\ApplicantIntake;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ViewApplicantIntake extends ViewRecord
{
    protected static string $resource = ApplicantIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markForEvaluation')
                ->label('Mark for Evaluation')
                ->icon('heroicon-o-magnifying-glass')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription('Confirms that submitted digital evidence is present and moves the application into formal Registrar evaluation.')
                ->visible(fn (): bool => $this->currentUserCanReview()
                    && in_array($this->applicantIntake()->status, [ApplicantIntake::StatusPending, ApplicantIntake::StatusActionRequired], true))
                ->action(fn (): mixed => $this->runReviewAction(
                    fn (User $actor): ApplicantIntake => app(ApplicantReviewService::class)
                        ->markForEvaluation($this->applicantIntake(), $actor),
                    'Application marked for evaluation',
                )),
            Action::make('approveApplication')
                ->label('Approve Application')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Approval is allowed only after every blocking requirement is resolved. The admitted Applicant continues through the exact-Term Registration Case; Student identity is created only at finalization.')
                ->visible(fn (): bool => $this->currentUserCanReview()
                    && $this->applicantIntake()->status === ApplicantIntake::StatusForEvaluation)
                ->action(fn (): mixed => $this->runReviewAction(
                    fn (User $actor): ApplicantIntake => app(ApplicantReviewService::class)
                        ->approve($this->applicantIntake(), $actor),
                    'Application approved for registration readiness',
                )),
        ];
    }

    private function applicantIntake(): ApplicantIntake
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ApplicantIntake, 404);

        return $record;
    }

    private function currentUserCanReview(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('review', $this->applicantIntake());
    }

    /** @param callable(User): ApplicantIntake $operation */
    private function runReviewAction(callable $operation, string $successTitle): mixed
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            Gate::authorize('review', $this->applicantIntake());
            $operation($actor);
            Notification::make()->title($successTitle)->success()->send();
            $this->refreshFormData(['status', 'reviewed_at', 'approved_at']);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Registrar action blocked')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        } catch (AuthorizationException $exception) {
            Notification::make()->title('Registrar action blocked')->body($exception->getMessage())->danger()->send();
        }

        return null;
    }
}
