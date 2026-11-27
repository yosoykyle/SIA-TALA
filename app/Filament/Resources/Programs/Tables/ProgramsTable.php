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
use WeakMap;

class ProgramsTable
{
    /** @var WeakMap<Program, bool>|null */
    private static ?WeakMap $unusedProgramCache = null;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['curriculumVersions.academicYears'])
                ->withCount(['curriculumVersions', 'studentProfiles', 'admissionCycles', 'admissionApplications', 'termCohorts']))
            ->searchPlaceholder('Program code or name')
            ->columns([
                TextColumn::make('name')->label('Program')->weight('semibold')->wrap()->searchable(['name', 'code'])
                    ->extraHeaderAttributes(['class' => 'tala-programs-col-program'])
                    ->extraCellAttributes(['class' => 'tala-programs-col-program'])
                    ->description(fn (Program $record): string => $record->code.($record->duration_years ? ' · '.$record->duration_years.' '.Str::plural('year', (int) $record->duration_years) : '').(! $record->is_active ? ' · Inactive' : '')),
                TextColumn::make('active_curriculum')->label('Active curriculum')
                    ->extraHeaderAttributes(['class' => 'tala-programs-col-curriculum'])
                    ->extraCellAttributes(['class' => 'tala-programs-col-curriculum'])
                    ->state(function (Program $record): string {
                        $active = CurriculumVersion::sortByLatestBatch(
                            $record->curriculumVersions
                                ->where('state', CurriculumVersion::StateActive)
                                ->values(),
                        );

                        if ($active->isEmpty()) {
                            return 'None yet';
                        }

                        $firstLabel = $active->first()->batchLabel();

                        if ($active->count() === 1) {
                            return $firstLabel;
                        }

                        return $firstLabel.' (+'.($active->count() - 1).' more)';
                    })
                    ->description(fn (Program $record): ?string => ($draft = $record->curriculumVersions->firstWhere('state', CurriculumVersion::StateDraft)) ? 'Draft in progress: '.$draft->batchLabel() : null)
                    ->wrap(),
                TextColumn::make('admissions_availability')
                    ->label('Admissions')
                    ->badge()
                    ->extraHeaderAttributes(['class' => 'tala-programs-col-admissions'])
                    ->extraCellAttributes(['class' => 'tala-programs-col-admissions'])
                    ->state(function (Program $record): string {
                        $availability = $record->admissionsAvailability();

                        if ($availability['available']) {
                            return 'Available in Admissions';
                        }

                        return $record->is_active ? 'Not available' : 'Program is inactive';
                    })
                    ->color(function (Program $record): string {
                        $availability = $record->admissionsAvailability();

                        if ($availability['available']) {
                            return 'success';
                        }

                        return $record->is_active ? 'warning' : 'gray';
                    })
                    ->description(function (Program $record): ?string {
                        $availability = $record->admissionsAvailability();

                        if ($availability['available']) {
                            return $availability['batch_label'] ? 'Ready for '.$availability['batch_label'] : null;
                        }

                        return $record->is_active ? $availability['reason'] : null;
                    })
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
            ], layout: FiltersLayout::Dropdown)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->size('sm'))
            ->filtersResetAction(fn (Action $action, Table $table): Action => $action->visible(fn (): bool => $table->isFiltered()))
            ->deferFilters(false)
            ->columnManager(false)
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
                    ->size('sm')
                    ->color('gray')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->authorize(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && $record->is_active && ! self::isUnusedProgram($record))
                    ->visible(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && $record->is_active && ! self::isUnusedProgram($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Program $record): string => "Deactivate {$record->code}?")
                    ->modalDescription(fn (Program $record): string => self::deactivationModalDescription($record))
                    ->modalSubmitActionLabel('Deactivate')
                    ->action(function (Program $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User && $actor->can('update', $record), 403);

                        $blockReason = self::deactivationBlockReason($record);

                        if ($blockReason !== null) {
                            Notification::make()
                                ->title('Program cannot be deactivated')
                                ->body($blockReason)
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
                    ->size('sm')
                    ->color('success')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->authorize(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && ! $record->is_active)
                    ->visible(fn (Program $record): bool => (auth()->user()?->can('update', $record) ?? false) && ! $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Program $record): string => "Reactivate {$record->code}?")
                    ->modalDescription('This program will become active again for new admission cycles and curriculum batches.')
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
                    ->size('sm')
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
                    ->size('sm')
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
                    ->label('Add program')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->visible(fn (HasTable $livewire): bool => ! filled($livewire->getTableSearch())
                        && ! $livewire->getTable()->isFiltered()
                        && (auth()->user()?->can('create', Program::class) ?? false)),
            ])
            ->toolbarActions([
                ProgramResource::createProgramAction()
                    ->label('Add program')
                    ->icon(Heroicon::OutlinedPlus)
                    ->color('primary')
                    ->size('sm')
                    ->visible(fn (): bool => auth()->user()?->can('create', Program::class) ?? false),
            ])
            ->defaultSort('code');
    }

    /**
     * Evaluate the refined deactivation guard:
     * block if the program has unresolved applicants in an open cycle OR is the only active program in an open cycle.
     */
    public static function deactivationBlockReason(Program $program): ?string
    {
        $openApplicants = $program->openCycleApplicantsCount();
        $isOnlyActive = $program->isOnlyActiveProgramInOpenCycle();

        if ($openApplicants === 0 && ! $isOnlyActive) {
            return null;
        }

        $openCycles = self::openPublishedAdmissionCycles($program);
        $cycleNames = self::formatOpenAdmissionCycleNames($openCycles);
        $cycleSuffix = $cycleNames !== '' ? " ({$cycleNames})" : '';

        if ($openApplicants > 0 && $isOnlyActive) {
            return sprintf(
                'Cannot deactivate %s while %d active %s %s in an open admission cycle%s and it is the only active program in that cycle. Resolve those applications and keep at least one active program open, or close the cycle first.',
                $program->code,
                $openApplicants,
                Str::plural('applicant', $openApplicants),
                $openApplicants === 1 ? 'is' : 'are',
                $cycleSuffix,
            );
        }

        if ($openApplicants > 0) {
            return sprintf(
                'Cannot deactivate %s while %d active %s %s in an open admission cycle%s. Resolve or transfer those applications, or close the cycle first.',
                $program->code,
                $openApplicants,
                Str::plural('applicant', $openApplicants),
                $openApplicants === 1 ? 'is' : 'are',
                $cycleSuffix,
            );
        }

        return sprintf(
            'Cannot deactivate %s because it is the only active program in an open admission cycle%s. Activate another program or close the cycle first.',
            $program->code,
            $cycleSuffix,
        );
    }

    /**
     * Count active continuing batches and active students in this program for teach-out messaging.
     *
     * @return array{batches: int, students: int}
     */
    public static function continuingBatchAndStudentCounts(Program $program): array
    {
        return self::continuingBatchAndStudentCountsByProgram([(int) $program->id])[(int) $program->id]
            ?? ['batches' => 0, 'students' => 0];
    }

    /**
     * Batch-count active continuing batches and active students across multiple programs.
     *
     * @param  list<int>  $programIds
     * @return array<int, array{batches: int, students: int}>
     */
    public static function continuingBatchAndStudentCountsByProgram(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $result = [];

        foreach ($programIds as $programId) {
            $result[(int) $programId] = ['batches' => 0, 'students' => 0];
        }

        $profilesByProgram = StudentProfile::query()
            ->whereIn('program_id', $programIds)
            ->active()
            ->where('lifecycle_status', StudentProfile::LifecycleActive)
            ->with('entryTerm:id,academic_year_id')
            ->get(['id', 'program_id', 'entry_term_id', 'curriculum_version_id'])
            ->groupBy('program_id');

        foreach ($profilesByProgram as $programId => $profiles) {
            $studentCount = $profiles->count();

            if ($studentCount === 0) {
                continue;
            }

            $batchKeys = $profiles
                ->map(function (StudentProfile $profile): string {
                    $ayId = $profile->entryTerm?->academic_year_id;

                    if ($ayId !== null) {
                        return 'ay:'.$ayId;
                    }

                    return 'cv:'.($profile->curriculum_version_id ?? 0);
                })
                ->unique()
                ->count();

            $result[(int) $programId] = [
                'batches' => max(1, $batchKeys),
                'students' => $studentCount,
            ];
        }

        return $result;
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
        $blockReason = self::deactivationBlockReason($program);

        if ($blockReason !== null) {
            return $blockReason;
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
        self::$unusedProgramCache ??= new WeakMap;

        if (! $fresh && isset(self::$unusedProgramCache[$program])) {
            return self::$unusedProgramCache[$program];
        }

        if (! $fresh) {
            if ((int) ($program->curriculum_versions_count ?? $program->curriculumVersions()->count()) > 0
                || (int) ($program->student_profiles_count ?? $program->studentProfiles()->count()) > 0
                || (int) ($program->admission_cycles_count ?? $program->admissionCycles()->count()) > 0
                || (int) ($program->admission_applications_count ?? AdmissionApplication::query()->where('program_id', $program->id)->count()) > 0
                || (int) ($program->term_cohorts_count ?? TermCohort::query()->where('program_id', $program->id)->count()) > 0) {
                return self::$unusedProgramCache[$program] = false;
            }
        } else {
            if ($program->curriculumVersions()->exists()
                || $program->studentProfiles()->exists()
                || $program->admissionCycles()->exists()
                || AdmissionApplication::query()->where('program_id', $program->id)->exists()
                || TermCohort::query()->where('program_id', $program->id)->exists()) {
                return false;
            }
        }

        $isUnused = ! FeePlan::query()->where('program_id', $program->id)->exists()
            && ! FeeRule::query()->where('program_id', $program->id)->exists()
            && ! StudentLifecycleChange::query()->where('target_program_id', $program->id)->exists();

        if (! $fresh) {
            self::$unusedProgramCache[$program] = $isUnused;
        }

        return $isUnused;
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
