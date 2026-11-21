<?php

namespace App\Filament\Resources\CurriculumVersions\Tables;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\CurriculumVersions\Schemas\CurriculumVersionForm;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CurriculumVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'program',
                    'effectiveEntryTerm.academicYear',
                    'entries.termOfferings.term.academicYear',
                ])
                ->withCount(['entries', 'studentProfiles']))
            ->searchPlaceholder('Search curricula')
            ->columns([
                TextColumn::make('program.code')
                    ->label('Program')
                    ->description(fn (CurriculumVersion $record): string => (string) ($record->program?->name ?? ''))
                    ->searchable(['program.code', 'program.name'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('name')->label('Curriculum')->searchable(['name'])->weight('bold')->wrap()
                    ->description(fn (CurriculumVersion $record): string => $record->entries_count.' '.str('subject')->plural($record->entries_count)),
                TextColumn::make('student_profiles_count')
                    ->label('Students')
                    ->formatStateUsing(fn (mixed $state): string => ((int) $state).' '.Str::plural('student', (int) $state))
                    ->badge()
                    ->color(fn (mixed $state): string => (int) $state > 0 ? 'info' : 'gray')
                    ->sortable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        CurriculumVersion::StateActive => 'success',
                        CurriculumVersion::StateDraft => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        CurriculumVersion::StateActive => 'Active',
                        CurriculumVersion::StateDraft => 'Draft',
                        CurriculumVersion::StateSuperseded, CurriculumVersion::StateArchived => 'Inactive',
                        default => str((string) $state)->headline()->toString(),
                    })
                    ->tooltip(fn (CurriculumVersion $record): ?string => match ($record->state) {
                        CurriculumVersion::StateSuperseded => 'Replaced by a newer curriculum; continuing students stay on this plan.',
                        CurriculumVersion::StateArchived => 'Archived historical curriculum.',
                        default => null,
                    })
                    ->sortable(),
                TextColumn::make('terms_and_intake')
                    ->label('Terms & intake')
                    ->state(fn (CurriculumVersion $record): ?HtmlString => self::formatTermsAndIntake($record))
                    ->tooltip(fn (CurriculumVersion $record): ?string => self::termsAndIntakeTooltip($record))
                    ->wrap()
                    ->placeholder('Not used in a term yet'),
            ])
            ->filters([
                SelectFilter::make('program_id')
                    ->label('Program')
                    ->relationship('program', 'code'),
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(CurriculumVersion::stateOptions()),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray'))
            ->deferFilters(false)
            ->extraAttributes(['class' => 'tala-data-table'])
            ->stackedOnMobile()
            ->recordUrl(fn (CurriculumVersion $record, Table $table): string => CurriculumVersionResource::getUrl('review', array_filter([
                'record' => $record,
                'programId' => $record->program_id,
                'oversightTermId' => self::oversightTermId($table),
            ], fn (mixed $value): bool => $value !== null)))
            ->recordActions([
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->button()
                    ->color('gray')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->authorize(fn (CurriculumVersion $record): bool => auth()->user()?->can('copy', $record) ?? false)
                    ->visible(fn (CurriculumVersion $record): bool => auth()->user()?->can('copy', $record) ?? false)
                    ->modalHeading('Duplicate curriculum')
                    ->modalDescription('Copy this curriculum and all its subjects into an editable draft for a new batch. Continuing student assignments stay unchanged.')
                    ->modalWidth(Width::TwoExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalSubmitActionLabel('Duplicate curriculum')
                    ->fillForm(fn (CurriculumVersion $record): array => [
                        'name' => $record->name.' (Draft)',
                        'effective_entry_term_id' => $record->effective_entry_term_id,
                    ])
                    ->schema(fn (): array => CurriculumVersionForm::planFields())
                    ->action(function (CurriculumVersion $record, array $data, Table $table, HasTable $livewire): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        $draft = app(CurriculumVersionLifecycleService::class)->copyToDraft(
                            actor: $actor,
                            source: $record,
                            versionCode: CurriculumVersionForm::draftCodeFor($record->program_id),
                            name: (string) $data['name'],
                            effectiveEntryTermId: filled($data['effective_entry_term_id'] ?? null)
                                ? (int) $data['effective_entry_term_id']
                                : null,
                        );

                        Notification::make()
                            ->title('Curriculum duplicated to draft')
                            ->body("Created {$draft->name} with {$draft->entries->count()} ".Str::plural('subject', $draft->entries->count()).'.')
                            ->success()
                            ->send();

                        $livewire->redirect(CurriculumVersionResource::getUrl('review', array_filter([
                            'record' => $draft,
                            'programId' => $draft->program_id,
                            'oversightTermId' => self::oversightTermId($table),
                        ], fn (mixed $value): bool => $value !== null)));
                    }),
                Action::make('discardDraft')
                    ->label('Discard draft')
                    ->button()
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->modalHeading('Discard this draft curriculum?')
                    ->modalDescription('Its draft subject placements will be permanently removed. Active curricula and student records are not affected.')
                    ->modalSubmitActionLabel('Discard draft')
                    ->authorize(fn (CurriculumVersion $record): bool => (auth()->user()?->can('update', $record) ?? false)
                        && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($record))
                    ->visible(fn (CurriculumVersion $record): bool => (auth()->user()?->can('update', $record) ?? false)
                        && app(CurriculumVersionLifecycleService::class)->canDiscardDraft($record))
                    ->action(function (CurriculumVersion $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(CurriculumVersionLifecycleService::class)->discardDraft($record, $actor);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Draft curriculum cannot be discarded')
                                ->body(collect($exception->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Draft curriculum discarded')
                            ->success()
                            ->send();
                    }),
                Action::make('openSheet')->button()->color('gray')
                    ->label('Open')->icon('heroicon-m-arrow-right')->iconPosition('after')
                    ->url(fn (CurriculumVersion $record, Table $table): string => CurriculumVersionResource::getUrl('review', array_filter([
                        'record' => $record,
                        'programId' => $record->program_id,
                        'oversightTermId' => self::oversightTermId($table),
                    ], fn (mixed $value): bool => $value !== null))),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'No matching curricula'
                : 'No curricula yet')
            ->emptyStateDescription(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'Try a different program, curriculum name, or status filter.'
                : 'Create a program study plan across year levels and semesters.')
            ->emptyStateActions([
                CurriculumVersionResource::createDraftAction(name: 'emptyCreateCurriculum')
                    ->visible(fn (HasTable $livewire): bool => ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', CurriculumVersion::class) ?? false)),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc');
    }

    private static function oversightTermId(Table $table): ?int
    {
        $livewire = $table->getLivewire();

        if (property_exists($livewire, 'oversightTermId') && is_int($livewire->oversightTermId) && $livewire->oversightTermId > 0) {
            return $livewire->oversightTermId;
        }

        return request()->integer('oversightTermId') ?: null;
    }

    private static function formatTermsAndIntake(CurriculumVersion $record): ?HtmlString
    {
        $terms = self::usedTerms($record);

        if ($terms->isNotEmpty()) {
            $labels = $terms
                ->take(2)
                ->map(fn (Term $term): string => e(self::formatTermLabel($term)));
            $extra = $terms->count() - 2;
            $text = $labels->implode(', ');

            if ($extra > 0) {
                $text .= ' <span class="text-xs text-gray-500">'.e("(+{$extra} more)").'</span>';
            }

            return new HtmlString($text);
        }

        if ($record->effectiveEntryTerm instanceof Term) {
            return new HtmlString('<span class="text-xs text-gray-500">First intake:</span> '.e(self::formatTermLabel($record->effectiveEntryTerm)));
        }

        return null;
    }

    private static function termsAndIntakeTooltip(CurriculumVersion $record): ?string
    {
        $terms = self::usedTerms($record);

        if ($terms->count() <= 2) {
            return null;
        }

        return $terms
            ->map(fn (Term $term): string => self::formatTermLabel($term))
            ->implode(', ');
    }

    /**
     * @return Collection<int, Term>
     */
    private static function usedTerms(CurriculumVersion $record): Collection
    {
        $offeringTerms = $record->entries
            ->flatMap(fn (CurriculumEntry $entry): Collection => $entry->termOfferings)
            ->map(fn (TermOffering $offering): ?Term => $offering->term)
            ->filter(fn (mixed $term): bool => $term instanceof Term);

        $enrollmentTerms = Term::query()
            ->with('academicYear')
            ->whereHas('enrollments.studentProfile', fn (Builder $query): Builder => $query->where('curriculum_version_id', $record->id))
            ->get();

        return $offeringTerms
            ->merge($enrollmentTerms)
            ->unique('id')
            ->sortByDesc(fn (Term $term): string => (string) ($term->starts_on?->toDateString() ?? ''))
            ->values();
    }

    private static function formatTermLabel(Term $term): string
    {
        $termLabel = trim((string) $term->label);
        $ayLabel = trim((string) ($term->academicYear?->label ?? ''));

        if ($termLabel !== '' && $ayLabel !== '' && ! str_contains($termLabel, $ayLabel)) {
            return "{$termLabel}, {$ayLabel}";
        }

        return $termLabel !== '' ? $termLabel : $ayLabel;
    }
}
