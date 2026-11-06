<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Tables;

use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Resources\StudentLifecycleChanges\Pages\ListStudentLifecycleChanges;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentLifecycleChangesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'studentProfile.program', 'studentProfile.user',
                'applicationAudit.causer',
                'term.academicYear', 'targetProgram', 'targetCurriculumVersion', 'expectedReturnTerm.academicYear',
            ]))
            ->view('filament.admin.student-lifecycle.workspace')
            ->deferFilters(false)
            ->paginationPageOptions([10, 25, 50])
            ->columns([
                TextColumn::make('studentProfile.student_number')
                    ->label('Student')
                    ->formatStateUsing(function (StudentLifecycleChange $record): string {
                        $profile = $record->studentProfile;

                        if (! $profile instanceof StudentProfile) {
                            return 'Student record unavailable';
                        }

                        return collect([
                            $profile->last_name,
                            $profile->first_name,
                        ])->filter()->implode(', ');
                    })
                    ->description(function (StudentLifecycleChange $record): string {
                        $profile = $record->studentProfile;

                        if (! $profile instanceof StudentProfile) {
                            return 'Student identity unavailable';
                        }

                        return collect([
                            $profile->student_number,
                            $profile->program?->name,
                        ])->filter()->implode(' · ');
                    })
                    ->searchable(
                        query: fn (Builder $query, string $search): Builder => $query->whereHas(
                            'studentProfile',
                            fn (Builder $profileQuery): Builder => $profileQuery->where(function (Builder $identityQuery) use ($search): void {
                                $identityQuery
                                    ->where('student_number', 'like', "%{$search}%")
                                    ->orWhere('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%");
                            }),
                        ),
                    )
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Change')
                    ->badge()
                    ->description(fn (StudentLifecycleChange $record): ?string => $record->term?->label)
                    ->formatStateUsing(fn (string $state): string => StudentLifecycleChange::typeOptions()[$state] ?? str($state)->headline()->toString()),
                TextColumn::make('effective_on')->label('Effective date')->date()->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (StudentLifecycleChange $record): string => $record->statusLabel())
                    ->description(fn (StudentLifecycleChange $record): string => match ($record->state) {
                        StudentLifecycleChange::StateRecordedApproved => 'Registrar · Apply when due or cancel',
                        StudentLifecycleChange::StateApplied => 'Registrar · Recorded; see effective date',
                        StudentLifecycleChange::StateCancelled => 'Registrar · No application pending',
                        default => 'Registrar · Review record',
                    })
                    ->wrap(),
            ])
            ->searchable(['authority'])
            ->searchPlaceholder('Student name or number')
            ->emptyStateHeading(fn (ListStudentLifecycleChanges $livewire): string => $livewire->hasTableSearch() || $livewire->getTable()->isFiltered()
                ? 'No matching student changes'
                : 'No student changes recorded')
            ->emptyStateDescription(fn (ListStudentLifecycleChanges $livewire): string => $livewire->hasTableSearch() || $livewire->getTable()->isFiltered()
                ? 'Clear the search or adjust the filters to find a recorded change.'
                : 'The Registrar records approved student changes here. Recorded changes remain available for review.')
            ->recordUrl(fn (StudentLifecycleChange $record, ListStudentLifecycleChanges $livewire): string => StudentLifecycleChangeResource::getUrl('index', ['change' => $record->id, 'oversightTermId' => $livewire->oversightTermId]))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('effective_on')->orderByDesc('id'))
            ->filters([
                SelectFilter::make('type')->label('Change')->placeholder('All changes')->options(StudentLifecycleChange::typeOptions()),
                SelectFilter::make('term')->label('Term')->placeholder('All terms')
                    ->options(fn (): array => Term::query()->with('academicYear')->orderByDesc('starts_on')->get()
                        ->mapWithKeys(fn ($term): array => [$term->id => collect([$term->label, $term->academicYear?->label])->filter()->implode(' · ')])->all())
                    ->searchable()->preload()
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, mixed $term): Builder => $query->where('term_id', $term))),
                SelectFilter::make('state')->label('Status')->options([
                    StudentLifecycleChange::StateRecordedApproved => 'Approval awaiting application',
                    StudentLifecycleChange::StateApplied => 'Recorded',
                    StudentLifecycleChange::StateCancelled => 'Cancelled',
                ]),
                Filter::make('effective_date')->schema([
                    DatePicker::make('from'), DatePicker::make('until'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('effective_on', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('effective_on', '<=', $date))),
            ])
            ->recordActions([
                Action::make('apply')
                    ->label(fn (StudentLifecycleChange $record): string => $record->type === StudentLifecycleChange::TypeProgramShift ? 'Apply program shift' : 'Apply change')
                    ->authorize('apply')
                    ->requiresConfirmation()
                    ->disabled(fn (StudentLifecycleChange $record): bool => $record->isFutureDated() || ($record->type === StudentLifecycleChange::TypeProgramShift && (! $record->targetProgram || ! $record->targetCurriculumVersion)))
                    ->modalDescription(fn (StudentLifecycleChange $record): string => 'Apply '.(StudentLifecycleChange::typeOptions()[$record->type] ?? 'this change').' effective '.$record->effective_on->format('M j, Y').'. Current sources are rechecked. Released grades and earlier records remain. No automatic refund, enrollment or class placement is created.')
                    ->visible(fn (StudentLifecycleChange $record): bool => $record->isPendingChange())
                    ->action(function (StudentLifecycleChange $record): void {
                        try {
                            app(StudentLifecycleService::class)->apply($record, auth()->user());
                        } catch (StudentLifecycleRuleViolation $exception) {
                            Notification::make()->title('Change was not applied')->body($exception->getMessage())->danger()->send();
                            throw new Halt;
                        }
                        Notification::make()->title('Change applied')->success()->send();
                    }),
                Action::make('cancel')
                    ->label(fn (StudentLifecycleChange $record): string => $record->type === StudentLifecycleChange::TypeProgramShift ? 'Cancel program shift' : 'Cancel approval')
                    ->authorize('cancel')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Cancel this recorded approval before application. Current student facts and earlier records stay unchanged.')
                    ->visible(fn (StudentLifecycleChange $record): bool => $record->isPendingChange())
                    ->action(function (StudentLifecycleChange $record): void {
                        try {
                            app(StudentLifecycleService::class)->cancel($record, auth()->user());
                        } catch (StudentLifecycleRuleViolation $exception) {
                            Notification::make()->title('Change was not cancelled')->body($exception->getMessage())->danger()->send();
                            throw new Halt;
                        }
                        Notification::make()->title('Approval cancelled')->success()->send();
                    }),
            ])
            ->stackedOnMobile();
    }
}
