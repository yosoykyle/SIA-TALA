<?php

namespace App\Filament\Resources\ExamAccessAccommodations\Tables;

use App\Actions\Finance\ExamAccessAccommodationService;
use App\Models\ExamAccessAccommodation;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExamAccessAccommodationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['studentProfile.user', 'academicYear', 'term', 'requester', 'reviewer']))
            ->columns([
                TextColumn::make('studentProfile.student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('studentProfile.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('academicYear.academic_year')
                    ->label('Academic Year')
                    ->placeholder('-'),
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->placeholder('-'),
                TextColumn::make('basis')
                    ->badge(),
                TextColumn::make('scope')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        ExamAccessAccommodation::StatusPending => 'warning',
                        ExamAccessAccommodation::StatusApproved => 'success',
                        ExamAccessAccommodation::StatusRejected, ExamAccessAccommodation::StatusRevoked => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('valid_from')
                    ->date(),
                TextColumn::make('valid_until')
                    ->date(),
                TextColumn::make('reviewer.name')
                    ->label('Reviewed By')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        ExamAccessAccommodation::StatusPending => 'Pending',
                        ExamAccessAccommodation::StatusApproved => 'Approved',
                        ExamAccessAccommodation::StatusRejected => 'Rejected',
                        ExamAccessAccommodation::StatusRevoked => 'Revoked',
                    ]),
                SelectFilter::make('basis')
                    ->options(ExamAccessAccommodation::basisOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::approveAction(),
                self::rejectAction(),
                self::revokeAction(),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    private static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->schema([
                Textarea::make('review_reason')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (ExamAccessAccommodation $record): bool => auth()->user()?->can('approve', $record) ?? false)
            ->action(fn (ExamAccessAccommodation $record, array $data) => self::runAction(
                fn (ExamAccessAccommodationService $service, User $actor): ExamAccessAccommodation => $service->approve(
                    $record,
                    $actor,
                    (string) $data['review_reason'],
                ),
                'Exam accommodation approved',
            ));
    }

    private static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->schema([
                Textarea::make('review_reason')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (ExamAccessAccommodation $record): bool => auth()->user()?->can('reject', $record) ?? false)
            ->action(fn (ExamAccessAccommodation $record, array $data) => self::runAction(
                fn (ExamAccessAccommodationService $service, User $actor): ExamAccessAccommodation => $service->reject(
                    $record,
                    $actor,
                    (string) $data['review_reason'],
                ),
                'Exam accommodation rejected',
            ));
    }

    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->schema([
                Textarea::make('revocation_reason')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (ExamAccessAccommodation $record): bool => auth()->user()?->can('revoke', $record) ?? false)
            ->action(fn (ExamAccessAccommodation $record, array $data) => self::runAction(
                fn (ExamAccessAccommodationService $service, User $actor): ExamAccessAccommodation => $service->revoke(
                    $record,
                    $actor,
                    (string) $data['revocation_reason'],
                ),
                'Exam accommodation revoked',
            ));
    }

    /**
     * @param  callable(ExamAccessAccommodationService, User): ExamAccessAccommodation  $callback
     */
    private static function runAction(callable $callback, string $successTitle): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $callback(app(ExamAccessAccommodationService::class), $actor);

            Notification::make()
                ->title($successTitle)
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Exam accommodation action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
