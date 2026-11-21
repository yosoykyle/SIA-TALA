<?php

namespace App\Filament\Resources\Programs\Tables;

use App\Filament\Resources\Programs\ProgramResource;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\CurriculumVersion;
use App\Models\FeePlan;
use App\Models\FeeRule;
use App\Models\Program;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\TermCohort;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProgramsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('curriculumVersions')
                ->withCount(['curriculumVersions', 'studentProfiles', 'admissionCycles']))
            ->searchPlaceholder('Program code or name')
            ->columns([
                TextColumn::make('name')->label('Program')->weight('semibold')->wrap()->searchable(['name', 'code'])
                    ->description(fn (Program $record): string => $record->code.($record->duration_years ? ' · '.$record->duration_years.' years' : '').(! $record->is_active ? ' · Inactive' : '')),
                TextColumn::make('active_curriculum')->label('Active curriculum')
                    ->state(fn (Program $record): string => $record->curriculumVersions->firstWhere('state', CurriculumVersion::StateActive)?->name ?? 'None yet')
                    ->description(fn (Program $record): ?string => ($draft = $record->curriculumVersions->firstWhere('state', CurriculumVersion::StateDraft)) ? 'Draft in progress: '.$draft->name : null)
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Status')
                    ->placeholder('All statuses')
                    ->options([
                        '1' => 'Active',
                        '0' => 'Inactive',
                    ]),
            ])
            ->filtersLayout(FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray'))
            ->deferFilters(false)
            ->extraAttributes(['class' => 'tala-data-table tala-programs-table'])
            ->striped()
            ->stackedOnMobile()
            ->recordUrl(fn (Program $record, Table $table): string => ProgramResource::getUrl('view', array_filter([
                'record' => $record,
                'oversightTermId' => self::oversightTermId($table),
            ], fn (mixed $value): bool => $value !== null)))
            ->recordActions([
                Action::make('deactivateProgram')
                    ->label('Deactivate')
                    ->button()
                    ->color('gray')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->authorize(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && $record->is_active && ! self::isUnusedProgram($record))
                    ->visible(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && $record->is_active && ! self::isUnusedProgram($record))
                    ->requiresConfirmation()
                    ->modalHeading('Deactivate this program?')
                    ->modalDescription(fn (Program $record): string => self::deactivationModalDescription($record))
                    ->modalSubmitActionLabel('Deactivate')
                    ->action(function (Program $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->can('update', $record), 403);

                        $openCycles = self::openPublishedAdmissionCycles($record);

                        if ($openCycles->isNotEmpty()) {
                            $cycleNames = self::formatOpenAdmissionCycleNames($openCycles);

                            Notification::make()
                                ->title('Program cannot be deactivated')
                                ->body("Cannot deactivate this program while it is open in Admission Cycle {$cycleNames}. Close or remove it from the cycle first.")
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update(['is_active' => false]);

                        activity()
                            ->performedOn($record)
                            ->causedBy($actor)
                            ->event('program_deactivated')
                            ->withProperties([
                                'program_code' => $record->code,
                                'program_name' => $record->name,
                            ])
                            ->log('Program deactivated');

                        Notification::make()
                            ->title('Program deactivated')
                            ->success()
                            ->send();
                    }),
                Action::make('reactivateProgram')
                    ->label('Reactivate')
                    ->button()
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->authorize(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && ! $record->is_active)
                    ->visible(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && ! $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading('Reactivate this program?')
                    ->modalDescription('This program will become active again for new admission cycles and draft curriculum placements.')
                    ->modalSubmitActionLabel('Reactivate')
                    ->action(function (Program $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->can('update', $record), 403);

                        $record->update(['is_active' => true]);

                        activity()
                            ->performedOn($record)
                            ->causedBy($actor)
                            ->event('program_reactivated')
                            ->withProperties([
                                'program_code' => $record->code,
                                'program_name' => $record->name,
                            ])
                            ->log('Program reactivated');

                        Notification::make()
                            ->title('Program reactivated')
                            ->success()
                            ->send();
                    }),
                Action::make('deleteProgram')
                    ->label('Delete program')
                    ->button()
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->authorize(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && self::isUnusedProgram($record))
                    ->visible(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && self::isUnusedProgram($record))
                    ->requiresConfirmation()
                    ->modalHeading('Delete this program?')
                    ->modalDescription('This unused program has no curricula, applications, or student records and will be permanently removed.')
                    ->modalSubmitActionLabel('Delete program')
                    ->action(function (Program $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->can('update', $record), 403);

                        $deleted = DB::transaction(function () use ($record, $actor): bool {
                            $locked = Program::query()->lockForUpdate()->findOrFail($record->id);

                            if (! self::isUnusedProgram($locked, fresh: true)) {
                                return false;
                            }

                            $properties = [
                                'program_id' => $locked->id,
                                'program_code' => $locked->code,
                                'program_name' => $locked->name,
                            ];

                            $locked->delete();

                            activity()
                                ->causedBy($actor)
                                ->event('program_deleted')
                                ->withProperties($properties)
                                ->log('Unused program deleted');

                            return true;
                        });

                        if (! $deleted) {
                            Notification::make()
                                ->title('Program cannot be deleted')
                                ->body('This program already has linked records. Deactivate it instead.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Program deleted')
                            ->success()
                            ->send();
                    }),
                Action::make('openProgram')
                    ->button()
                    ->color('gray')
                    ->label('Open')
                    ->icon('heroicon-m-arrow-right')
                    ->iconPosition('after')
                    ->url(fn (Program $record, Table $table): string => ProgramResource::getUrl('view', array_filter([
                        'record' => $record,
                        'oversightTermId' => self::oversightTermId($table),
                    ], fn (mixed $value): bool => $value !== null))),
            ])
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'No matching programs'
                : 'No programs yet')
            ->emptyStateDescription(fn (HasTable $livewire): string => filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered()
                ? 'Try a different code, name, or status filter.'
                : 'Add a degree or diploma program to begin building curricula.')
            ->emptyStateActions([
                ProgramResource::createProgramAction('emptyCreateProgram')
                    ->visible(fn (HasTable $livewire): bool => ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', Program::class) ?? false)),
            ])
            ->toolbarActions([])
            ->defaultSort('code');
    }

    /**
     * @return Collection<int, AdmissionCycle>
     */
    public static function openPublishedAdmissionCycles(Program $program): Collection
    {
        $now = now();

        return $program->admissionCycles()
            ->where('state', AdmissionCycle::StatePublished)
            ->where(fn (Builder $query): Builder => $query->whereNull('opens_at')->orWhere('opens_at', '<=', $now))
            ->where(fn (Builder $query): Builder => $query->whereNull('closes_at')->orWhere('closes_at', '>', $now))
            ->orderBy('closes_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, AdmissionCycle>  $cycles
     */
    public static function formatOpenAdmissionCycleNames(Collection $cycles): string
    {
        return $cycles
            ->map(function (AdmissionCycle $cycle): string {
                $label = trim((string) $cycle->label);
                $code = trim((string) $cycle->code);

                if ($label !== '' && $code !== '' && $label !== $code) {
                    return "{$label} ({$code})";
                }

                return $label !== '' ? $label : $code;
            })
            ->filter()
            ->implode(', ');
    }

    public static function deactivationModalDescription(Program $program): string
    {
        $openCycles = self::openPublishedAdmissionCycles($program);

        if ($openCycles->isNotEmpty()) {
            $cycleNames = self::formatOpenAdmissionCycleNames($openCycles);

            return "Cannot deactivate this program while it is open in Admission Cycle {$cycleNames}. Close or remove it from the cycle first.";
        }

        $activeCurriculaCount = $program->curriculumVersions()
            ->where('state', CurriculumVersion::StateActive)
            ->count();
        $activeStudentsCount = $program->studentProfiles()
            ->active()
            ->where('lifecycle_status', StudentProfile::LifecycleActive)
            ->count();
        $openApplicationsCount = AdmissionApplication::query()
            ->where('program_id', $program->id)
            ->whereIn('application_state', [
                AdmissionApplication::StateDraft,
                AdmissionApplication::StateSubmitted,
                AdmissionApplication::StateActionNeeded,
                AdmissionApplication::StateAdmitted,
            ])
            ->count();

        $impactSummary = sprintf(
            'Current impact: %d active %s, %d enrolled %s, and %d open %s.',
            $activeCurriculaCount,
            Str::plural('curriculum', $activeCurriculaCount),
            $activeStudentsCount,
            Str::plural('student', $activeStudentsCount),
            $openApplicationsCount,
            Str::plural('application', $openApplicationsCount),
        );

        return $impactSummary.' Deactivating stops new intakes and curriculum placements. Active curricula and enrolled students remain intact and continue normally.';
    }

    public static function isUnusedProgram(Program $program, bool $fresh = false): bool
    {
        if (! $fresh) {
            if ((int) ($program->curriculum_versions_count ?? $program->curriculumVersions()->count()) > 0) {
                return false;
            }

            if ((int) ($program->student_profiles_count ?? $program->studentProfiles()->count()) > 0) {
                return false;
            }

            if ((int) ($program->admission_cycles_count ?? $program->admissionCycles()->count()) > 0) {
                return false;
            }
        } else {
            if ($program->curriculumVersions()->exists()
                || $program->studentProfiles()->exists()
                || $program->admissionCycles()->exists()) {
                return false;
            }
        }

        return ! AdmissionApplication::query()->where('program_id', $program->id)->exists()
            && ! TermCohort::query()->where('program_id', $program->id)->exists()
            && ! FeePlan::query()->where('program_id', $program->id)->exists()
            && ! FeeRule::query()->where('program_id', $program->id)->exists()
            && ! StudentLifecycleChange::query()->where('target_program_id', $program->id)->exists();
    }

    private static function oversightTermId(Table $table): ?int
    {
        $livewire = $table->getLivewire();

        if (property_exists($livewire, 'oversightTermId') && is_int($livewire->oversightTermId) && $livewire->oversightTermId > 0) {
            return $livewire->oversightTermId;
        }

        return request()->integer('oversightTermId') ?: null;
    }
}
