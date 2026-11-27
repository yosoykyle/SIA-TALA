<?php

namespace App\Filament\Resources\AdmissionCycles\Tables;

use App\Actions\Admissions\ChangeAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\AdmissionCycles\Schemas\AdmissionCycleForm;
use App\Models\AdmissionCycle;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class AdmissionCyclesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'tala-admissions-table tala-admission-cycles-table'])
            ->striped()
            ->searchPlaceholder('Search cycles')
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('label')->label('Admission cycle')->searchable(['label', 'code'])->wrap()->weight('semibold')
                            ->description(fn (AdmissionCycle $record): string => $record->code),
                        TextColumn::make('term.label')->label('Academic Year')->prefix('Academic Year: ')
                            ->formatStateUsing(fn (?string $state, AdmissionCycle $record): ?string => $record->term instanceof Term ? AdmissionCycleForm::academicYearOptionLabel($record->term) : $state)
                            ->wrap()->placeholder('Academic Year not selected'),
                    ])->space(1),
                    TextColumn::make('availability')->label('Public applications')
                        ->prefix('Applications: ')
                        ->badge()
                        ->state(fn (AdmissionCycle $record): string => match (true) {
                            $record->state === AdmissionCycle::StateDraft => 'Draft',
                            $record->state === AdmissionCycle::StateCancelled => 'Cancelled',
                            $record->state !== AdmissionCycle::StatePublished || ! $record->opens_at || ! $record->closes_at => 'Window unavailable',
                            $record->opens_at->isFuture() => 'Scheduled',
                            $record->closes_at <= now() => 'Closed',
                            default => 'Open',
                        })
                        ->color(fn (string $state): string => match ($state) {
                            'Open' => 'success',
                            'Scheduled' => 'info',
                            'Cancelled' => 'danger',
                            'Window unavailable' => 'warning',
                            default => 'gray',
                        })
                        ->description(fn (AdmissionCycle $record): string => match (true) {
                            $record->state !== AdmissionCycle::StatePublished => 'First submissions unavailable',
                            ! $record->opens_at || ! $record->closes_at => 'Review the cycle dates',
                            $record->opens_at->isFuture() => 'Opens '.$record->opens_at->timezone('Asia/Manila')->format('M j, Y · g:i A'),
                            default => ($record->closes_at <= now() ? 'Closed ' : 'Closes ').$record->closes_at->timezone('Asia/Manila')->format('M j, Y · g:i A'),
                        })
                        ->wrap(),
                ])->from('md'),
                Panel::make([
                    Stack::make([
                        Split::make([
                            TextColumn::make('lifecycle_field_label')->state('Lifecycle')->grow(false),
                            TextColumn::make('state')->label('Lifecycle')->alignEnd()->sortable(),
                        ])->from('sm'),
                        Split::make([
                            TextColumn::make('opens_field_label')->state('Opens')->grow(false),
                            TextColumn::make('opens_at')->label('Applications open')->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))->alignEnd()->sortable(),
                        ])->from('sm'),
                        Split::make([
                            TextColumn::make('closes_field_label')->state('Public closing')->grow(false),
                            TextColumn::make('closes_at')->label('Public closing')->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))->alignEnd()->sortable(),
                        ])->from('sm'),
                        Split::make([
                            TextColumn::make('correction_field_label')->state('New correction requests end')->grow(false),
                            TextColumn::make('correction_closes_at')->label('Correction requests end')->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))->alignEnd()->placeholder('Not set'),
                        ])->from('sm'),
                        Split::make([
                            TextColumn::make('programs_field_label')->state('Programs')->grow(false),
                            TextColumn::make('programs_count')->counts('programs')->label('Programs')->alignEnd(),
                        ])->from('sm'),
                    ])->space(3),
                ])->collapsible(),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filter cycles')->tooltip('Narrow this cycle list by state or target term'))
            ->filters([
                SelectFilter::make('state')->options([
                    AdmissionCycle::StateDraft => 'Draft',
                    AdmissionCycle::StatePublished => 'Published',
                    AdmissionCycle::StateCancelled => 'Cancelled',
                ]),
                SelectFilter::make('term_id')
                    ->label('Academic Year')
                    ->relationship('term', 'label', fn (Builder $query): Builder => $query->with('academicYear'))
                    ->getOptionLabelFromRecordUsing(fn (Term $record): string => AdmissionCycleForm::academicYearOptionLabel($record))
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ViewAction::make()->label('Open')->button()->color('gray'),
                EditAction::make()->iconButton()->tooltip('Edit draft cycle')->color('gray')
                    ->visible(fn (AdmissionCycle $record): bool => $record->state === AdmissionCycle::StateDraft),
                Action::make('discardDraft')
                    ->iconButton()
                    ->icon('heroicon-o-trash')
                    ->tooltip('Discard draft cycle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalWidth(Width::Large)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalHeading('Discard cycle draft?')
                    ->modalDescription(fn (AdmissionCycle $record): string => "Permanently removes {$record->label} ({$record->code}) and any draft requirement sets.")
                    ->modalSubmitActionLabel('Discard draft')
                    ->visible(fn (AdmissionCycle $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->action(function (AdmissionCycle $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(ChangeAdmissionCycle::class)->discardDraft($record, $actor);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Draft cycle cannot be discarded')
                                ->body($exception->validator->errors()->first())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Draft cycle discarded')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No admission cycles')
            ->emptyStateDescription('Create an admission cycle to configure dates, accepting programs, and requirements.')
            ->emptyStateActions([
                Action::make('prepareAdmissionCycle')
                    ->label('Prepare admission cycle')
                    ->icon('heroicon-o-plus')
                    ->url(fn (): string => AdmissionCycleResource::getUrl('create'))
                    ->visible(fn (): bool => auth()->user()?->can('create', AdmissionCycle::class) ?? false),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'));
    }
}
