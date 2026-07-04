<?php

namespace App\Filament\Resources\ApplicantIntakes\Pages;

use App\Actions\Applicants\HandOverApprovedApplicant;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewApplicantIntake extends ViewRecord
{
    protected static string $resource = ApplicantIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('handOverToStudent')
                ->label('Hand Over to Student')
                ->icon('heroicon-o-academic-cap')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Hand over approved applicant to Student Hub')
                ->modalDescription('This creates the official student profile, carries forward eligible checklist items, and activates Student Hub access for the applicant account.')
                ->modalSubmitActionLabel('Hand Over to Student')
                ->visible(fn (): bool => $this->currentUserCanHandOver())
                ->action(function (): void {
                    $record = $this->applicantIntake();
                    $actor = auth()->user();

                    try {
                        if (! $actor instanceof User) {
                            throw new AuthorizationException('You must be signed in to hand over an applicant.');
                        }

                        Gate::authorize('handOver', $record);

                        $studentProfile = app(HandOverApprovedApplicant::class)->execute(
                            $record,
                            $actor,
                        );

                        $this->sendHandoverSuccessNotification($studentProfile);
                    } catch (ValidationException $exception) {
                        $this->sendHandoverFailureNotification($exception->validator->errors()->first());
                    } catch (AuthorizationException $exception) {
                        $this->sendHandoverFailureNotification($exception->getMessage());
                    }
                }),
            Action::make('downloadIdentityDocument')
                ->label('Download Identity Document')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => filled($this->applicantIntake()->identity_evidence_reference))
                ->action(fn (): StreamedResponse => Storage::disk('local')->download(
                    $this->applicantIntake()->identity_evidence_reference,
                )),
        ];
    }

    private function applicantIntake(): ApplicantIntake
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ApplicantIntake, 404);

        return $record;
    }

    private function currentUserCanHandOver(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('handOver', $this->applicantIntake());
    }

    private function sendHandoverSuccessNotification(StudentProfile $studentProfile): void
    {
        Notification::make()
            ->title('Applicant handed over to Student Hub')
            ->body("Student profile {$studentProfile->student_number} is now active.")
            ->success()
            ->actions([
                Action::make('viewStudentProfile')
                    ->label('View Student Profile')
                    ->button()
                    ->url(StudentProfileResource::getUrl('view', ['record' => $studentProfile])),
            ])
            ->send();
    }

    private function sendHandoverFailureNotification(?string $message): void
    {
        Notification::make()
            ->title('Applicant handover blocked')
            ->body($message ?: 'The applicant intake could not be handed over.')
            ->danger()
            ->send();
    }
}
