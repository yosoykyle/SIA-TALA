<?php

namespace App\Filament\Support;

use App\Actions\PublicContent\ManagePublicContent;
use App\Models\FaqEntry;
use App\Models\PublicNotice;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class PublicContentActions
{
    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('publication_status')->label('Publication state')
            ->state(fn (PublicNotice|FaqEntry $record): string => $record->publicationLabel())
            ->badge()->icon(fn (string $state): Heroicon => match ($state) {
                'Published' => Heroicon::OutlinedCheckCircle,
                'Scheduled' => Heroicon::OutlinedClock,
                default => Heroicon::OutlinedDocumentText,
            })->color(fn (string $state): string => match ($state) {
                'Published' => 'success', 'Scheduled' => 'info', default => 'gray',
            });
    }

    public static function tableActions(): array
    {
        return [
            Action::make('preview')->label('Preview')->icon(Heroicon::OutlinedEye)
                ->modalContent(fn (PublicNotice|FaqEntry $record): View => view('filament.public-content.preview', ['record' => $record]))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close preview'),
            ActionGroup::make([
                EditAction::make()->label(fn (PublicNotice|FaqEntry $record): string => $record->wasPublished() ? 'Create successor draft' : 'Edit draft'),
                self::moveAction('up'),
                self::moveAction('down'),
                self::publicationAction('publish'),
                self::publicationAction('unpublish'),
                DeleteAction::make()->label('Delete unpublished draft'),
            ])->label('Content actions'),
        ];
    }

    private static function moveAction(string $direction): Action
    {
        return Action::make('move'.ucfirst($direction))->label('Move '.$direction)
            ->icon($direction === 'up' ? Heroicon::OutlinedArrowUp : Heroicon::OutlinedArrowDown)
            ->authorize('update')->requiresConfirmation()
            ->visible(fn (PublicNotice|FaqEntry $record): bool => ! $record->wasPublished() || $record->publicationLabel() === 'Published')
            ->fillForm(fn (PublicNotice|FaqEntry $record): array => [
                'revision' => $record->revision,
                'order_signature' => app(ManagePublicContent::class)->orderSignature($record),
            ])
            ->schema([
                Hidden::make('revision')->required()->rules(['integer', 'min:1']),
                Hidden::make('order_signature')->required()->rules(['string', 'size:64']),
            ])
            ->modalDescription(fn (PublicNotice|FaqEntry $record): string => $record->wasPublished()
                ? 'System Administration will publish successor versions swapping this item with its adjacent published item in the same group. The new order becomes public immediately; earlier versions remain in history. A pending successor draft blocks the move.'
                : 'Change the draft position by one. This does not publish the draft. Publication will check the position against other effective content.')
            ->modalSubmitActionLabel(fn (PublicNotice|FaqEntry $record): string => $record->wasPublished() ? 'Publish reordered content' : 'Move draft')
            ->action(function (PublicNotice|FaqEntry $record, array $data) use ($direction): void {
                try {
                    app(ManagePublicContent::class)->move($record, auth()->user(), $direction, (int) $data['revision'], $data['order_signature']);
                    Notification::make()->title('Content moved '.$direction)->success()->send();
                } catch (ValidationException $exception) {
                    self::reportFailure($exception);
                }
            });
    }

    private static function publicationAction(string $operation): Action
    {
        return Action::make($operation)->label(ucfirst($operation).' content')
            ->icon($operation === 'publish' ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedEyeSlash)
            ->authorize('update')->requiresConfirmation()
            ->visible(fn (PublicNotice|FaqEntry $record): bool => $operation === 'publish' ? ! $record->wasPublished() : $record->isPublished())
            ->fillForm(fn (PublicNotice|FaqEntry $record): array => ['revision' => $record->revision])
            ->schema([Hidden::make('revision')->required()->rules(['integer', 'min:1'])])
            ->modalDescription($operation === 'publish'
                ? 'System Administration will make this saved version public for the displayed window. A successor replaces its earlier version when it becomes effective.'
                : 'System Administration will hide this content and its published versions from public view. All drafts and publication history remain.')
            ->modalContent(fn (PublicNotice|FaqEntry $record): View => view('filament.public-content.preview', ['record' => $record]))
            ->modalSubmitActionLabel(ucfirst($operation).' content')
            ->action(function (PublicNotice|FaqEntry $record, array $data) use ($operation): void {
                try {
                    app(ManagePublicContent::class)->{$operation}($record, auth()->user(), (int) $data['revision']);
                    Notification::make()->title('Content '.$operation.'ed')->success()->send();
                } catch (ValidationException $exception) {
                    self::reportFailure($exception);
                }
            });
    }

    public static function reportFailure(ValidationException $exception): void
    {
        Notification::make()->title('Content was not changed')
            ->body(implode(' ', $exception->validator->errors()->all()))->danger()->persistent()->send();
    }
}
