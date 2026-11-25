<?php

namespace App\Filament\Support;

use App\Actions\PublicContent\ManagePublicContent;
use App\Models\FaqEntry;
use App\Models\PublicNotice;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use LogicException;

trait EditsPublicContent
{
    #[Locked]
    public int $loadedRevision = 1;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->loadedRevision = (int) $data['revision'];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PublicNotice && ! $record instanceof FaqEntry) {
            throw new LogicException('Public Content editing requires a notice or FAQ record.');
        }

        try {
            $saved = app(ManagePublicContent::class)->save($record, auth()->user(), $data, $this->loadedRevision);
        } catch (ValidationException $exception) {
            PublicContentActions::reportFailure($exception);
            throw new Halt;
        }
        $this->record = $saved;
        $this->loadedRevision = $saved->revision;

        return $saved;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->previewFormAction(),
        ];
    }

    protected function getSaveFormAction(): Action
    {
        $record = $this->getRecord();
        if (! $record instanceof PublicNotice && ! $record instanceof FaqEntry) {
            throw new LogicException('Public Content editing requires a notice or FAQ record.');
        }

        return parent::getSaveFormAction()
            ->label($record->wasPublished() ? 'Save updated draft' : 'Save as draft')
            ->color('gray');
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->url($this->getResourceUrl())
            ->color('gray');
    }

    /** @return array<int, Action> */
    protected function getFormActions(): array
    {
        return [
            $this->publishAction(),
            $this->getSaveFormAction(),
            $this->getCancelFormAction(),
        ];
    }

    public function previewFormAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->modalHeading('Landing page preview')
            ->modalContent(function (): View {
                $record = $this->getRecord();
                if (! $record instanceof PublicNotice && ! $record instanceof FaqEntry) {
                    throw new LogicException('Public Content editing requires a notice or FAQ record.');
                }

                $raw = $this->form->getRawState();
                $preview = $record->replicate();
                $preview->forceFill([
                    'title' => $raw['title'] ?? $record->title ?? null,
                    'message' => $raw['message'] ?? $record->message ?? null,
                    'question' => $raw['question'] ?? $record->question ?? null,
                    'answer' => $raw['answer'] ?? $record->answer ?? null,
                    'category' => $raw['category'] ?? $record->category ?? null,
                    'link_url' => $raw['link_url'] ?? $record->link_url ?? null,
                    'link_label' => $raw['link_label'] ?? $record->link_label ?? null,
                    'visible_from' => $raw['visible_from'] ?? $record->visible_from,
                    'visible_until' => $raw['visible_until'] ?? $record->visible_until,
                ]);
                $preview->updated_at = $record->updated_at ?? now();

                return view('filament.public-content.preview', ['record' => $preview]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close preview');
    }

    public function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Publish to public landing page?')
            ->modalDescription('This saves your changes and publishes them immediately (or at the scheduled start time).')
            ->modalSubmitActionLabel('Publish')
            ->action(function (): void {
                $record = $this->getRecord();
                if (! $record instanceof PublicNotice && ! $record instanceof FaqEntry) {
                    throw new LogicException('Public Content editing requires a notice or FAQ record.');
                }

                $data = $this->form->getState();
                $user = auth()->user();

                try {
                    $actions = app(ManagePublicContent::class);
                    $saved = $actions->save($record, $user, $data, $this->loadedRevision);
                    $actions->publish($saved, $user, $saved->revision);
                } catch (ValidationException $exception) {
                    PublicContentActions::reportFailure($exception);

                    return;
                }

                Notification::make()
                    ->title('Published to public landing page')
                    ->success()
                    ->send();

                $this->redirect($this->getResourceUrl());
            });
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResourceUrl();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Draft saved. Review and publish it from Public Content.';
    }
}
