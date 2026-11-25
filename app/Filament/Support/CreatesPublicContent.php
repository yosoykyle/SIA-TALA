<?php

namespace App\Filament\Support;

use App\Actions\PublicContent\ManagePublicContent;
use App\Models\FaqEntry;
use App\Models\PublicNotice;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use LogicException;

trait CreatesPublicContent
{
    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            $this->previewFormAction(),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Save as draft')
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
            $this->getCreateFormAction(),
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
                /** @var class-string<PublicNotice>|class-string<FaqEntry> $model */
                $model = static::getResource()::getModel();
                $raw = $this->form->getRawState();
                $preview = new $model;
                $preview->forceFill([
                    'title' => $raw['title'] ?? null,
                    'message' => $raw['message'] ?? null,
                    'question' => $raw['question'] ?? null,
                    'answer' => $raw['answer'] ?? null,
                    'category' => $raw['category'] ?? null,
                    'link_url' => $raw['link_url'] ?? null,
                    'link_label' => $raw['link_label'] ?? null,
                    'visible_from' => $raw['visible_from'] ?? null,
                    'visible_until' => $raw['visible_until'] ?? null,
                    'state' => 'Draft',
                    'is_published' => false,
                ]);

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
            ->modalDescription('This saves your draft and publishes it immediately (or at the scheduled start time).')
            ->modalSubmitActionLabel('Publish')
            ->action(function (): void {
                /** @var class-string<PublicNotice>|class-string<FaqEntry> $model */
                $model = static::getResource()::getModel();
                if ($model !== PublicNotice::class && $model !== FaqEntry::class) {
                    throw new LogicException('Public Content creation requires a notice or FAQ model.');
                }

                $data = $this->form->getState();
                $user = auth()->user();

                try {
                    $actions = app(ManagePublicContent::class);
                    $record = $actions->create($model, $user, $data);
                    $actions->publish($record, $user, $record->revision);
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
}
