<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\CurriculumVersion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCurriculumVersion extends ViewRecord
{
    protected static string $resource = CurriculumVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => $this->curriculumVersion()->state === CurriculumVersion::StateDraft),
            Action::make('recordApproval')
                ->label('Record External Approval')
                ->icon(Heroicon::OutlinedDocumentCheck)
                ->schema([
                    TextInput::make('approval_reference')
                        ->label('Approval Reference')
                        ->helperText('Enter the resolution, board, or institutional reference that approved this curriculum outside TALA.')
                        ->required()
                        ->maxLength(255),
                ])
                ->visible(fn (): bool => $this->currentUserCan('recordApproval'))
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    $this->record = app(CurriculumVersionLifecycleService::class)->recordApproval(
                        actor: $actor,
                        curriculumVersion: $this->curriculumVersion(),
                        approvalReference: (string) ($data['approval_reference'] ?? ''),
                    );

                    Notification::make()
                        ->title('External approval recorded')
                        ->body('Review the activation impact before making this curriculum active for future handovers.')
                        ->success()
                        ->send();
                }),
            Action::make('activateCurriculum')
                ->label('Set Active Curriculum')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Activate this curriculum for future handovers?')
                ->modalDescription(fn (): string => $this->activationDescription())
                ->modalSubmitActionLabel('Confirm Activation')
                ->visible(fn (): bool => $this->currentUserCan('activate'))
                ->action(function (): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    $this->record = app(CurriculumVersionLifecycleService::class)->activate(
                        actor: $actor,
                        curriculumVersion: $this->curriculumVersion(),
                        confirmed: true,
                    );

                    Notification::make()
                        ->title('Curriculum activated')
                        ->body('The previous Active version was superseded for future handovers. Existing student curriculum assignments were preserved.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function activationDescription(): string
    {
        $impact = app(CurriculumVersionLifecycleService::class)
            ->activationImpact($this->curriculumVersion());
        $previous = $impact['active_version_code'] ?? 'none';
        $readiness = $impact['readiness_errors'] === []
            ? 'All referenced Course Specifications are ready.'
            : 'Activation is currently blocked: '.implode(' ', $impact['readiness_errors']);

        return "Previous Active version: {$previous}. Entries: {$impact['entries']}. Existing student curriculum locks preserved: {$impact['existing_student_locks']}. {$readiness}";
    }

    private function curriculumVersion(): CurriculumVersion
    {
        $record = $this->getRecord();
        abort_unless($record instanceof CurriculumVersion, 404);

        return $record;
    }

    private function currentUserCan(string $ability): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can($ability, $this->curriculumVersion());
    }
}
