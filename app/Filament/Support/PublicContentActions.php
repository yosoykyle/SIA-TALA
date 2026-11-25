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
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;

class PublicContentActions
{
    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('publication_status')->label('Status')
            ->state(fn (PublicNotice|FaqEntry $record): string => $record->publicationLabel())
            ->badge()->icon(fn (string $state): Heroicon => match ($state) {
                'Published' => Heroicon::OutlinedCheckCircle,
                'Scheduled' => Heroicon::OutlinedClock,
                default => Heroicon::OutlinedDocumentText,
            })->color(fn (string $state): string => match ($state) {
                'Published' => 'success', 'Scheduled' => 'info', default => 'gray',
            });
    }

    public static function scheduleColumn(): TextColumn
    {
        return TextColumn::make('schedule')
            ->label('Schedule')
            ->state(fn (PublicNotice|FaqEntry $record): string => 'From: '.($record->visible_from?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'On publication'))
            ->description(fn (PublicNotice|FaqEntry $record): string => 'Until: '.($record->visible_until?->timezone('Asia/Manila')->format('M j, Y g:i A') ?? 'Until unpublished'));
    }

    public static function tableActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon(Heroicon::OutlinedEye)
                ->button()
                ->color('gray')
                ->size(Size::Small)
                ->modalContent(fn (PublicNotice|FaqEntry $record): View => view('filament.public-content.preview', ['record' => $record]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close preview'),
            self::publicationAction('publish')
                ->label('Publish')
                ->button()
                ->color('primary')
                ->size(Size::Small),
            self::publicationAction('unpublish')
                ->label('Unpublish')
                ->button()
                ->color('gray')
                ->size(Size::Small),
            ActionGroup::make([
                EditAction::make()
                    ->label(fn (PublicNotice|FaqEntry $record): string => $record->wasPublished() ? 'Prepare update' : 'Edit draft')
                    ->icon(Heroicon::OutlinedPencilSquare),
                DeleteAction::make()
                    ->label('Delete draft')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash),
            ])
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->iconButton()
                ->outlined()
                ->color('gray')
                ->size(Size::Small)
                ->tooltip('More actions'),
        ];
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
                ? 'Publish this saved draft to the public landing page for the selected schedule. Any earlier published version will be replaced when this becomes active.'
                : 'Hide this item from the public landing page. Drafts and version history remain saved.')
            ->modalContent(fn (PublicNotice|FaqEntry $record): View => view('filament.public-content.preview', ['record' => $record]))
            ->modalSubmitActionLabel(ucfirst($operation))
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
