<?php

namespace App\Filament\Resources\ApplicantIntakes\Pages;

use App\Actions\Applicants\ApplicantDuplicateCandidateFinder;
use App\Actions\Applicants\ApplicantReviewService;
use App\Actions\Applicants\HandOverApprovedApplicant;
use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\ApplicantIntake;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
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
                ->modalDescription('Approval is allowed only after every handover-blocking requirement is resolved. Handover remains a separate explicit action.')
                ->visible(fn (): bool => $this->currentUserCanReview()
                    && $this->applicantIntake()->status === ApplicantIntake::StatusForEvaluation)
                ->action(fn (): mixed => $this->runReviewAction(
                    fn (User $actor): ApplicantIntake => app(ApplicantReviewService::class)
                        ->approve($this->applicantIntake(), $actor),
                    'Application approved for handover',
                )),
            Action::make('handOverToStudent')
                ->label('Hand Over to Student')
                ->icon('heroicon-o-academic-cap')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Confirm applicant-to-student handover')
                ->modalDescription('Review the applicant, target program, active curriculum rule, and any returning-student profile match before creating or reusing the official student record.')
                ->modalContent(fn () => view('filament.admin.applicant-intakes.handover-preview', [
                    'intake' => $this->applicantIntake()->load(['program', 'term', 'checklistItems']),
                    'candidates' => $this->existingProfileOptions(),
                ]))
                ->schema(fn (): array => $this->handoverSchema())
                ->modalSubmitActionLabel('Confirm Hand Over')
                ->visible(fn (): bool => $this->currentUserCanHandOver())
                ->action(function (array $data): void {
                    $record = $this->applicantIntake();
                    $actor = auth()->user();

                    try {
                        if (! $actor instanceof User) {
                            throw new AuthorizationException('You must be signed in to hand over an applicant.');
                        }

                        Gate::authorize('handOver', $record);
                        $existingProfile = ($data['profile_resolution'] ?? null) === 'existing'
                            ? StudentProfile::query()->findOrFail((int) $data['existing_profile_id'])
                            : null;
                        $studentProfile = app(HandOverApprovedApplicant::class)->execute(
                            $record,
                            $actor,
                            $existingProfile,
                        );

                        $this->sendHandoverSuccessNotification($studentProfile);
                    } catch (ValidationException $exception) {
                        $this->sendHandoverFailureNotification($exception->validator->errors()->first());
                    } catch (AuthorizationException $exception) {
                        $this->sendHandoverFailureNotification($exception->getMessage());
                    }
                }),
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
        $intake = $this->applicantIntake();

        return $user instanceof User
            && $user->can('handOver', $intake)
            && ! app(ApplicantDuplicateCandidateFinder::class)
                ->requiresNonReturningIdentityReview($intake);
    }

    private function currentUserCanReview(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('review', $this->applicantIntake());
    }

    /** @return list<Radio|Select> */
    private function handoverSchema(): array
    {
        if ($this->applicantIntake()->admission_category !== ApplicantIntake::AdmissionCategoryReturning) {
            return [];
        }

        return [
            Radio::make('profile_resolution')
                ->label('Returning Student Profile Decision')
                ->options([
                    'new' => 'Create a new student profile',
                    'existing' => 'Reuse the confirmed matching student profile',
                ])
                ->descriptions([
                    'new' => 'Use only when the Registrar confirms that no existing official record should be reused.',
                    'existing' => 'Select an active, unmerged profile with the same name and birth date.',
                ])
                ->default($this->existingProfileOptions() === [] ? 'new' : 'existing')
                ->required()
                ->live(),
            Select::make('existing_profile_id')
                ->label('Confirmed Existing Profile')
                ->options($this->existingProfileOptions())
                ->searchable()
                ->native(false)
                ->visible(fn (Get $get): bool => $get('profile_resolution') === 'existing')
                ->required(fn (Get $get): bool => $get('profile_resolution') === 'existing'),
        ];
    }

    /** @return array<int, string> */
    private function existingProfileOptions(): array
    {
        $intake = $this->applicantIntake();

        return app(ApplicantDuplicateCandidateFinder::class)
            ->find($intake)
            ->mapWithKeys(fn (StudentProfile $profile): array => [
                $profile->id => "{$profile->student_number} — {$profile->first_name} {$profile->last_name}",
            ])
            ->all();
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
