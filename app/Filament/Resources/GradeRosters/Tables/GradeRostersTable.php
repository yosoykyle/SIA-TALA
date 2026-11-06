<?php

namespace App\Filament\Resources\GradeRosters\Tables;

use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Models\GradeRoster;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\Layout\View as RecordView;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GradeRostersTable
{
    public static function configure(Table $table, ?string $defaultState = null): Table
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead) && ! auth()->user()->hasRole(User::StaffRoleRegistrar)) {
            return self::headTable($table);
        }

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['termOffering.term.academicYear', 'termOffering.curriculumEntry.courseSpecification.course', 'section', 'faculty'])
                ->withCount([
                    'rows as current_members_count' => fn (Builder $rows): Builder => $rows->where('is_current_membership', true),
                    'rows as incomplete_results_count' => fn (Builder $rows): Builder => $rows->where('is_current_membership', true)->where('final_result', 'INC'),
                    'rows as recorded_results_count' => fn (Builder $rows): Builder => $rows->where('is_current_membership', true)->whereNotNull('final_result'),
                ]))
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('termOffering.curriculumEntry.courseSpecification.title')->label('Course')->weight('semibold')->size('lg')
                            ->icon('heroicon-o-book-open')->searchable(query: fn (Builder $query, string $search): Builder => $query
                            ->whereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $specification): Builder => $specification
                                ->where('title', 'like', '%'.$search.'%')
                                ->orWhereHas('course', fn (Builder $course): Builder => $course->where('code', 'like', '%'.$search.'%'))))->wrap()
                            ->description(fn (GradeRoster $record): string => collect([$record->termOffering?->curriculumEntry?->courseSpecification?->course?->code, $record->section?->code])->filter()->implode(' · ')),
                        TextColumn::make('section.code')->label('Term and class')->searchable()
                            ->formatStateUsing(fn (GradeRoster $record): string => collect([$record->termOffering?->term?->label, $record->termOffering?->term?->academicYear?->label])->filter()->implode(' · '))->color('gray')->wrap(),
                        TextColumn::make('faculty.name')->label('Faculty')->icon('heroicon-o-user')->searchable()->color('gray')->wrap(),
                    ])->space(2),
                    Stack::make([
                        TextColumn::make('completion')->label('Results recorded')
                            ->state(fn (GradeRoster $record): string => sprintf('%d of %d', $record->recorded_results_count, $record->current_members_count))
                            ->description('Results recorded'),
                        TextColumn::make('incomplete_results_count')->label('Incomplete grades')
                            ->formatStateUsing(fn (int $state): string => $state > 0 ? $state.' INC' : '')->color('gray'),
                        TextColumn::make('state')->badge()->color(fn (string $state): string => $state === GradeRoster::StateSubmitted ? 'primary' : 'gray')
                            ->formatStateUsing(fn (string $state): string => $state === GradeRoster::StateReleased ? 'Released' : str($state)->lower()->headline()->toString()),
                    ])->grow(false)->space(1),
                ])->from('md'),
            ])
            ->filters([
                SelectFilter::make('term')->label('Term')->searchable()
                    ->options(fn (): array => self::termOptions())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null,
                        fn (Builder $scope, mixed $termId): Builder => $scope->whereHas('termOffering', fn (Builder $offering): Builder => $offering->where('term_id', $termId)))),
                SelectFilter::make('state')->label('Show')->placeholder('All class states')->default($defaultState)->options([
                    GradeRoster::StateDraft => 'Drafts', GradeRoster::StateSubmitted => 'Awaiting review',
                    GradeRoster::StateReturned => 'Returned to Faculty', GradeRoster::StateReleased => 'Released grades',
                    GradeRoster::StateLateNotSubmitted => 'Awaiting late submission',
                ]),
            ])
            ->recordActions([
                ViewAction::make()->label(fn (GradeRoster $record, HasTable $livewire): string => $record->state === GradeRoster::StateSubmitted ? 'Review roster' : 'View roster')
                    ->button()->color(fn (GradeRoster $record, HasTable $livewire): string => $record->state === GradeRoster::StateSubmitted ? 'primary' : 'gray')
                    ->url(fn (GradeRoster $record, HasTable $livewire): string => self::rosterUrl($record, $livewire)),
            ])
            ->recordUrl(fn (GradeRoster $record, HasTable $livewire): string => self::rosterUrl($record, $livewire))
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered() ? 'No matching class rosters' : 'No class rosters yet')
            ->emptyStateDescription(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'Clear the search or adjust the term and state filters.'
                : 'Class records appear after teaching assignments and official enrollment are recorded. Submissions, returns and releases stay in each class history.')
            ->stackedOnMobile()
            ->searchPlaceholder('Course, class or Faculty')
            ->defaultSort('submitted_at', 'desc');
    }

    private static function rosterUrl(GradeRoster $record, HasTable $livewire): string
    {
        return GradeRosterResource::getUrl('view', [
            'record' => $record,
            'viewTab' => 'grade-review',
            'queue' => GradesAndCompletion::classQueueParameters([
                'filters' => $livewire->tableFilters ?? [],
                'search' => $livewire->getTableSearch(),
                'sort' => $livewire->tableSort ?? null,
                'page' => $livewire->getTablePage(),
            ]),
        ]);
    }

    /** @return array<int, string> */
    public static function termOptions(): array
    {
        return Term::query()->with('academicYear')->orderByDesc('starts_on')->get()
            ->mapWithKeys(fn (Term $term): array => [$term->id => collect([$term->label, $term->academicYear?->label])->filter()->implode(' · ')])->all();
    }

    private static function headTable(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'accounting-records-table academic-rosters-table'])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['termOffering.term.academicYear', 'termOffering.curriculumEntry.courseSpecification.course', 'section', 'teachingAssignment.faculty', 'faculty'])
                ->withCount(['rows as current_members_count' => fn (Builder $rows): Builder => $rows->where('is_current_membership', true)]))
            ->columns([RecordView::make('filament.admin.grade-rosters.head-record-row')])
            ->searchable([fn (Builder $query, string $search): Builder => $query->where(fn (Builder $scope): Builder => $scope
                ->whereHas('termOffering.term', fn (Builder $term): Builder => $term->where('label', 'like', "%{$search}%"))
                ->orWhereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $course): Builder => $course
                    ->where('title', 'like', "%{$search}%")
                    ->orWhereHas('course', fn (Builder $code): Builder => $code->where('code', 'like', "%{$search}%")))
                ->orWhereHas('section', fn (Builder $section): Builder => $section->where('code', 'like', "%{$search}%"))
                ->orWhereHas('faculty', fn (Builder $faculty): Builder => $faculty->where('name', 'like', "%{$search}%")))])
            ->searchPlaceholder('Course, class, term or faculty')
            ->filters([
                SelectFilter::make('term')->label('Term')->searchable()
                    ->options(fn (): array => self::termOptions())
                    ->default(fn (HasTable $livewire): ?int => $livewire->oversightTermId ?? null)
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null,
                        fn (Builder $scope, mixed $termId): Builder => $scope->whereHas('termOffering', fn (Builder $offering): Builder => $offering->where('term_id', $termId)))),
                SelectFilter::make('state')->options([
                    GradeRoster::StateDraft => 'Draft', GradeRoster::StateSubmitted => 'Submitted',
                    GradeRoster::StateReturned => 'Returned', GradeRoster::StateReleased => 'Posted and released',
                    GradeRoster::StateLateNotSubmitted => 'Late / not submitted',
                ]),
            ])
            ->recordUrl(null)->recordAction(null)->recordActions([])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered() ? 'No matching class rosters' : 'No class rosters yet')
            ->emptyStateDescription(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'Clear the search or adjust the term and state filters.'
                : 'Class records appear after teaching assignments and official enrollment are recorded. Ask the Registrar to check the class sources if a roster is expected.')
            ->defaultSort('id', 'desc');
    }
}
