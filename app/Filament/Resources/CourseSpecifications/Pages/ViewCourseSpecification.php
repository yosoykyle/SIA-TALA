<?php

namespace App\Filament\Resources\CourseSpecifications\Pages;

use App\Actions\AcademicSetup\CourseSpecificationLifecycleService;
use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Models\CourseSpecification;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCourseSpecification extends ViewRecord
{
    protected static string $resource = CourseSpecificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => $this->courseSpecification()->state === CourseSpecification::StateDraft),
            Action::make('copyToDraft')
                ->label('Copy to New Draft')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->schema([
                    TextInput::make('revision_code')
                        ->label('New Revision Identifier')
                        ->required()
                        ->maxLength(255),
                ])
                ->visible(fn (): bool => $this->currentUserCan('copy'))
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    $draft = app(CourseSpecificationLifecycleService::class)->copyToDraft(
                        actor: $actor,
                        source: $this->courseSpecification(),
                        revisionCode: (string) ($data['revision_code'] ?? ''),
                    );

                    Notification::make()
                        ->title('Draft revision created')
                        ->body("{$draft->revision_code} is ready for review and editing.")
                        ->success()
                        ->send();

                    $this->redirect(CourseSpecificationResource::getUrl('edit', ['record' => $draft]));
                }),
            Action::make('activateRevision')
                ->label('Activate Revision')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Activation locks this academic definition against direct editing. Future material changes require a new Draft revision.')
                ->visible(fn (): bool => $this->currentUserCan('activate'))
                ->action(function (): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    $this->record = app(CourseSpecificationLifecycleService::class)
                        ->activate($actor, $this->courseSpecification());

                    Notification::make()
                        ->title('Course Specification activated')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function courseSpecification(): CourseSpecification
    {
        $record = $this->getRecord();
        abort_unless($record instanceof CourseSpecification, 404);

        return $record;
    }

    private function currentUserCan(string $ability): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can($ability, $this->courseSpecification());
    }
}
