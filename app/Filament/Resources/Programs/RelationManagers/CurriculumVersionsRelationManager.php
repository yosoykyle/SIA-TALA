<?php

namespace App\Filament\Resources\Programs\RelationManagers;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class CurriculumVersionsRelationManager extends RelationManager
{
    #[Url]
    public ?int $oversightTermId = null;

    protected static bool $isLazy = false;

    protected static string $relationship = 'curriculumVersions';

    protected static ?string $title = 'Curricula';

    public function isReadOnly(): bool
    {
        return ! $this->canManage();
    }

    public function table(Table $table): Table
    {
        $program = $this->ownerProgram();
        $curriculumUrl = fn (CurriculumVersion $record): string => CurriculumVersionResource::getUrl('review', array_filter([
            'record' => $record,
            'programId' => $program->id,
            'oversightTermId' => $this->oversightTermId(),
        ], fn (mixed $value): bool => $value !== null));

        return $table
            ->recordTitleAttribute('name')
            ->extraAttributes(['class' => 'tala-data-table'])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('effectiveEntryTerm.academicYear')
                ->withCount('entries')
                ->orderByRaw("CASE state WHEN 'ACTIVE' THEN 1 WHEN 'DRAFT' THEN 2 WHEN 'SUPERSEDED' THEN 3 ELSE 4 END")
                ->orderByDesc('id'))
            ->groups([
                Group::make('state')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn (CurriculumVersion $record): string => $record->state)
                    ->getTitleFromRecordUsing(fn (CurriculumVersion $record): string => match ($record->state) {
                        CurriculumVersion::StateActive => 'Active',
                        CurriculumVersion::StateDraft => 'Draft',
                        default => 'Past study plans',
                    })
                    ->orderQueryUsing(fn (Builder $query): Builder => $query
                        ->orderByRaw("CASE state WHEN 'ACTIVE' THEN 1 WHEN 'DRAFT' THEN 2 WHEN 'SUPERSEDED' THEN 3 ELSE 4 END")
                        ->orderByDesc('id'))
                    ->collapsible(),
            ])
            ->defaultGroup('state')
            ->groupingSettingsHidden()
            ->heading('Curricula')
            ->columns([
                TextColumn::make('name')->label('Curriculum')->weight('semibold')->wrap()
                    ->description(fn (CurriculumVersion $record): string => $record->entries_count.' '.str('subject')->plural($record->entries_count)),
                TextColumn::make('effectiveEntryTerm.label')->label('First student intake')
                    ->formatStateUsing(fn (?string $state, CurriculumVersion $record): string => trim(($state ?? '').' · '.($record->effectiveEntryTerm?->academicYear?->label ?? ''), ' ·'))
                    ->placeholder('Not set')->wrap(),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => CurriculumVersion::stateOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        CurriculumVersion::StateActive => 'success',
                        CurriculumVersion::StateDraft => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options(CurriculumVersion::stateOptions()),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray')->labeledFrom(null))
            ->deferFilters(false)
            ->stackedOnMobile()
            ->headerActions([
                CurriculumVersionResource::createDraftAction($program->id, $this->oversightTermId(), 'createCurriculumDraft')
                    ->color(fn (): string => $program->curriculumVersions()->exists() ? 'gray' : 'primary'),
            ])
            ->recordUrl(fn (CurriculumVersion $record): string => $curriculumUrl($record))
            ->recordActions([
                Action::make('openCurriculumSheet')
                    ->button()
                    ->color('gray')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-right')
                    ->iconPosition('after')
                    ->url(fn (CurriculumVersion $record): string => $curriculumUrl($record)),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'No matching curricula'
                : 'No curricula yet')
            ->emptyStateDescription(fn (): string => $this->canManage()
                ? 'Create the first study plan for '.$program->code.' across year levels and semesters.'
                : 'No curriculum has been recorded for this program yet.')
            ->emptyStateActions([
                CurriculumVersionResource::createDraftAction($program->id, $this->oversightTermId(), 'emptyCreateCurriculumDraft')
                    ->visible(fn (): bool => $this->canManage()),
            ])
            ->toolbarActions([]);
    }

    private function canManage(): bool
    {
        return $this->actor()->can('update', $this->ownerProgram());
    }

    private function ownerProgram(): Program
    {
        $owner = $this->getOwnerRecord();
        abort_unless($owner instanceof Program, 404);

        return $owner;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function oversightTermId(): ?int
    {
        if (is_int($this->oversightTermId) && $this->oversightTermId > 0) {
            return $this->oversightTermId;
        }

        $termId = request()->integer('oversightTermId');

        return $termId > 0 ? $termId : null;
    }
}
