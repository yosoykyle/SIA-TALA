<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Applicants\WithdrawApplicantIntake;
use App\Models\ApplicantIntake;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.applicant.pages.dashboard';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('withdrawApplication')
                ->label('Withdraw Application')
                ->icon('heroicon-o-archive-box-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Withdraw this application?')
                ->modalDescription('The application will remain in the audit record, but it can no longer continue through online review. Contact the Registrar if you need assistance afterward.')
                ->modalSubmitActionLabel('Withdraw Application')
                ->visible(fn (): bool => $this->canWithdraw())
                ->action(function (): void {
                    $applicant = Auth::user();
                    $intake = $this->getIntake();

                    abort_unless($applicant instanceof User && $intake instanceof ApplicantIntake, 403);

                    try {
                        app(WithdrawApplicantIntake::class)->execute($intake, $applicant);
                        Notification::make()->title('Application withdrawn')->success()->send();
                        $this->redirect(self::getUrl());
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Application cannot be withdrawn')
                            ->body($exception->validator->errors()->first())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * Get the authenticated user's applicant intake record with relationships.
     */
    public function getIntake(): ?ApplicantIntake
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return ApplicantIntake::query()
            ->with(['checklistItems.reviewer', 'checklistItems.documentEvidence.reviewer', 'program', 'term'])
            ->where('user_id', $user->id)
            ->first();
    }

    private function canWithdraw(): bool
    {
        $intake = $this->getIntake();

        return $intake instanceof ApplicantIntake
            && in_array($intake->status, [ApplicantIntake::StatusDraft, ApplicantIntake::StatusPending], true)
            && $intake->reviewed_at === null
            && $intake->handed_over_at === null;
    }
}
