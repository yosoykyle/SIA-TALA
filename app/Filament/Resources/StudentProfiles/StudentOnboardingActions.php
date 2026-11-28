<?php

namespace App\Filament\Resources\StudentProfiles;

use App\Actions\StudentProfiles\OnboardExistingStudent;
use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Actions\StudentProfiles\StudentAccountActivation;
use App\Models\AcademicYear;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

final class StudentOnboardingActions
{
    /**
     * Build consolidated Registrar-only header dropdowns for adding students,
     * filter-aware exports, and account activation invites.
     *
     * @param  Closure(): bool  $isVisible
     * @return list<Action|ActionGroup>
     */
    public static function make(Closure $isVisible): array
    {
        return [
            ActionGroup::make([
                self::importStudentsAction($isVisible),
                self::addExistingStudentAction($isVisible),
            ])
                ->label('Add students')
                ->button()
                ->color('primary')
                ->icon(Heroicon::OutlinedChevronDown)
                ->iconPosition(IconPosition::After)
                ->dropdownWidth(Width::ExtraSmall)
                ->visible($isVisible),
            ActionGroup::make([
                self::exportFilteredStudentDirectoryAction($isVisible),
                self::exportActivationStatusAction($isVisible),
                self::openGradeSheetExportAction($isVisible),
            ])
                ->label('Export')
                ->button()
                ->color('gray')
                ->icon(Heroicon::OutlinedChevronDown)
                ->iconPosition(IconPosition::After)
                ->dropdownWidth(Width::ExtraSmall)
                ->visible($isVisible),
            ActionGroup::make([
                self::sendPendingActivationInvitesAction($isVisible),
            ])
                ->label('More actions')
                ->button()
                ->color('gray')
                ->icon(Heroicon::OutlinedChevronDown)
                ->iconPosition(IconPosition::After)
                ->dropdownWidth(Width::ExtraSmall)
                ->visible($isVisible),
        ];
    }

    /**
     * @param  Closure(): bool  $isVisible
     */
    public static function importStudentsAction(Closure $isVisible): Action
    {
        return Action::make('importStudents')
            ->label('Import from spreadsheet')
            ->color('primary')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible($isVisible)
            ->authorize($isVisible)
            ->url(fn (): string => StudentProfileResource::getUrl('import'));
    }

    /**
     * @param  Closure(): bool  $isVisible
     */
    public static function addExistingStudentAction(Closure $isVisible): Action
    {
        return Action::make('addExistingStudent')
            ->label('Add continuing student')
            ->color('gray')
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible($isVisible)
            ->authorize($isVisible)
            ->modalHeading('Add continuing student')
            ->modalDescription('Onboard a single existing Servitech student into TALA with cohort curriculum resolution, optional prior-term quick-credit, and a 7-day Student Hub activation invite.')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Onboard student')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('student_number')
                        ->label('Student Number')
                        ->placeholder('Leave blank to auto-allocate')
                        ->maxLength(64)
                        ->extraInputAttributes([
                            'autocorrect' => 'off',
                            'autocapitalize' => 'none',
                            'spellcheck' => 'false',
                        ])
                        ->helperText('Optional. Leave blank to auto-allocate the next institutional Student Number.'),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->live(onBlur: true)
                        ->maxLength(255)
                        ->extraInputAttributes([
                            'autocorrect' => 'off',
                            'autocapitalize' => 'none',
                            'spellcheck' => 'false',
                        ]),
                    TextInput::make('first_name')
                        ->label('First Name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('middle_name')
                        ->label('Middle Name')
                        ->maxLength(255),
                    TextInput::make('last_name')
                        ->label('Last Name')
                        ->required()
                        ->maxLength(255),
                    DatePicker::make('birth_date')
                        ->label('Birth Date')
                        ->native(false),
                    TextInput::make('prior_identifier')
                        ->label('LRN / Prior ID')
                        ->placeholder('Optional learner reference number')
                        ->maxLength(255)
                        ->extraInputAttributes([
                            'autocorrect' => 'off',
                            'autocapitalize' => 'none',
                            'spellcheck' => 'false',
                        ]),
                    TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->placeholder('Optional mobile or contact number')
                        ->maxLength(64),
                ]),
                Section::make('Cohort, Curriculum & Standing')
                    ->compact()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            Select::make('program_id')
                                ->label('Program')
                                ->required()
                                ->searchable()
                                ->preload()
                                ->options(fn (): array => self::programOptions())
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    $set('prior_curriculum_entry_ids', []);
                                    $set('completed_through_term', null);

                                    if (blank($state)) {
                                        $set('curriculum_version_id', null);

                                        return;
                                    }

                                    $resolvedId = self::resolveDefaultCurriculumId(
                                        programId: (int) $state,
                                        entryAcademicYearId: filled($get('entry_academic_year_id')) ? (int) $get('entry_academic_year_id') : null,
                                    );
                                    $set('curriculum_version_id', $resolvedId);
                                }),
                            Select::make('entry_academic_year_id')
                                ->label('Entry Academic Year (Batch)')
                                ->placeholder('Optional — select cohort entry year')
                                ->searchable()
                                ->preload()
                                ->options(fn (): array => AcademicYear::query()
                                    ->orderByDesc('starts_on')
                                    ->orderByDesc('id')
                                    ->pluck('label', 'id')
                                    ->all())
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                    $programId = $get('program_id');
                                    if (blank($programId)) {
                                        return;
                                    }

                                    $resolvedId = self::resolveDefaultCurriculumId(
                                        programId: (int) $programId,
                                        entryAcademicYearId: filled($state) ? (int) $state : null,
                                    );
                                    $set('curriculum_version_id', $resolvedId);
                                    $set('completed_through_term', null);
                                    $set('prior_curriculum_entry_ids', []);
                                })
                                ->helperText('Anchors the student to their cohort batch curriculum without requiring a historical Term record.'),
                            Select::make('curriculum_version_id')
                                ->label('Curriculum Version')
                                ->required()
                                ->searchable()
                                ->live()
                                ->disabled(fn (Get $get): bool => blank($get('program_id')))
                                ->options(fn (Get $get): array => self::curriculumOptions($get('program_id')))
                                ->afterStateUpdated(function (Set $set): void {
                                    $set('completed_through_term', null);
                                    $set('prior_curriculum_entry_ids', []);
                                }),
                            Select::make('completed_through_term')
                                ->label('Prior Terms Completed (Quick-Credit)')
                                ->placeholder('None — individual advising')
                                ->disabled(fn (Get $get): bool => blank($get('curriculum_version_id')))
                                ->options(fn (Get $get): array => self::completedThroughOptions($get('curriculum_version_id')))
                                ->helperText('Credits all curriculum subjects through the chosen term as CREDIT for Day-1 next-term placement. Leave blank for irregulars or individual advising.'),
                            Select::make('academic_standing')
                                ->label('Academic Standing')
                                ->options([
                                    StudentProfile::StandingRegular => 'Regular',
                                    StudentProfile::StandingIrregular => 'Irregular',
                                    StudentProfile::StandingProbationary => 'Probationary',
                                    StudentProfile::StandingDeficient => 'Deficient',
                                    StudentProfile::StandingCompletionCandidate => 'Completion Candidate',
                                    StudentProfile::StandingGraduationCandidate => 'Graduation Candidate',
                                ])
                                ->default(StudentProfile::StandingRegular)
                                ->required(),
                            Select::make('lifecycle_status')
                                ->label('Lifecycle Status')
                                ->options([
                                    StudentProfile::LifecycleActive => 'Active',
                                    StudentProfile::LifecycleLeaveOfAbsence => 'Leave of Absence',
                                    StudentProfile::LifecycleCompleted => 'Completed',
                                    StudentProfile::LifecycleInactive => 'Inactive',
                                ])
                                ->default(StudentProfile::LifecycleActive)
                                ->required(),
                            TextInput::make('financial_hold_note')
                                ->label('Prior Financial Hold Note')
                                ->placeholder('Optional prior balance note')
                                ->maxLength(1000)
                                ->columnSpanFull()
                                ->helperText('If provided, creates active Accounting holds blocking both next-term enrollment and official TOR/graduation release.'),
                        ]),
                        Select::make('prior_curriculum_entry_ids')
                            ->label('Additional Prior Completed Subjects (Optional)')
                            ->multiple()
                            ->searchable()
                            ->disabled(fn (Get $get): bool => blank($get('curriculum_version_id')))
                            ->options(fn (Get $get): array => self::curriculumEntryOptions($get('curriculum_version_id')))
                            ->helperText('Optional. Use for individual passed subjects beyond or instead of Quick-Credit above.'),
                    ]),
                Section::make('Account & Activation')
                    ->compact()
                    ->schema([
                        Checkbox::make('link_existing_applicant')
                            ->label('Link existing applicant account if this email is already registered')
                            ->default(false)
                            ->helperText(function (Get $get): string {
                                $email = strtolower(trim((string) ($get('email') ?? '')));
                                if ($email !== '') {
                                    $applicant = User::query()->where('email', $email)->first();
                                    if ($applicant instanceof User && ! $applicant->studentProfile()->exists() && $applicant->hasAssignedRole('applicant')) {
                                        return 'Existing applicant account found for this email. Check this box to link the student profile to that account and archive any open draft intake.';
                                    }
                                }

                                return 'Leave unchecked unless resolving an existing applicant account collision for this continuing student.';
                            }),
                        Toggle::make('send_activation_email')
                            ->label('Send 7-day activation email now')
                            ->default(false)
                            ->helperText('Off by default. When turned on, emails a 7-day Servitech Student Hub account activation link immediately after onboarding.'),
                    ]),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $payload = $data;
                if (filled($data['completed_through_term'] ?? null) && ! filled($data['completed_through_year_level'] ?? null)) {
                    [$yearLevel, $termType] = self::parseCompletedThroughValue((string) $data['completed_through_term']);
                    if ($yearLevel !== null && $termType !== null) {
                        $payload['completed_through_year_level'] = $yearLevel;
                        $payload['completed_through_term_type'] = $termType;
                    }
                }
                unset($payload['completed_through_term']);

                $result = app(OnboardExistingStudent::class)->execute($payload, $actor);
                $profile = $result['profile'];
                $parts = [];
                if ($result['prior_credits_created'] > 0) {
                    $parts[] = $result['prior_credits_created'].' prior '.Str::plural('subject', $result['prior_credits_created']).' credited.';
                }
                if ($result['linked_existing_applicant']) {
                    $parts[] = 'Linked existing applicant account.';
                }
                $parts[] = $result['activation_sent']
                    ? '7-day activation link sent to '.$profile->email.'.'
                    : 'Profile created without sending an activation email.';

                Notification::make()
                    ->title('Continuing student onboarded: '.$profile->student_number)
                    ->body(implode(' ', $parts))
                    ->success()
                    ->send();
            });
    }

    /**
     * Header action to dispatch 7-day activation invites to all eligible pending/unactivated
     * students in the current directory scope (with an explicit confirmation count).
     *
     * @param  Closure(): bool  $isVisible
     */
    public static function sendPendingActivationInvitesAction(Closure $isVisible): Action
    {
        return Action::make('sendPendingActivationInvites')
            ->label('Send activation invites')
            ->color('gray')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible($isVisible)
            ->authorize($isVisible)
            ->requiresConfirmation()
            ->modalHeading('Send student activation invites')
            ->modalDescription(function (mixed $livewire): string {
                $count = self::eligiblePendingProfiles($livewire)->count();

                if ($count === 0) {
                    return 'There are currently 0 pending student accounts awaiting activation in this view.';
                }

                return "Send 7-day Servitech Student Hub activation links to {$count} pending ".Str::plural('student', $count).' currently awaiting account setup? Already activated accounts are skipped automatically.';
            })
            ->modalSubmitActionLabel('Send activation invites')
            ->action(function (mixed $livewire): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $profiles = self::eligiblePendingProfiles($livewire);

                if ($profiles->isEmpty()) {
                    Notification::make()
                        ->title('No pending activation invites')
                        ->body('Every student in the current view has already completed activation or has no sign-in email.')
                        ->warning()
                        ->send();

                    return;
                }

                self::dispatchBulkInvites($profiles, $actor);
            });
    }

    /**
     * Header action to export a filtered student list CSV matching active directory filters
     * (program, batch/entry year, enrolled term/section, lifecycle, activation status, search).
     *
     * @param  Closure(): bool  $isVisible
     */
    public static function exportFilteredStudentDirectoryAction(Closure $isVisible): Action
    {
        return Action::make('exportStudentDirectory')
            ->label('Export student list (.csv)')
            ->color('gray')
            ->icon(Heroicon::OutlinedTableCells)
            ->visible($isVisible)
            ->authorize($isVisible)
            ->action(function (mixed $livewire): BinaryFileResponse {
                $profiles = self::scopedDirectoryProfiles($livewire);
                $termId = self::activeEnrollmentTermId($livewire);
                $prefix = self::directoryExportPrefix($livewire);

                $exportService = app(OngoingStudentSpreadsheetExportService::class);
                $path = $exportService->exportStudentDirectory($profiles, $termId, $prefix);

                return $exportService->downloadAndDelete($path, basename($path), 'csv');
            });
    }

    /**
     * Header action jumping straight to the Prior Grade Sheet download tab, pre-scoped to active program filter.
     *
     * @param  Closure(): bool  $isVisible
     */
    public static function openGradeSheetExportAction(Closure $isVisible): Action
    {
        return Action::make('openGradeSheetExport')
            ->label('Download prior grade sheet (.xlsx)')
            ->color('gray')
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->visible($isVisible)
            ->authorize($isVisible)
            ->url(function (mixed $livewire): string {
                $params = ['mode' => 'matrix'];
                $programId = self::activeProgramFilterId($livewire);
                if ($programId !== null) {
                    $params['program'] = $programId;
                }

                return StudentProfileResource::getUrl('import', $params);
            });
    }

    /**
     * Header action to export a formula-safe CSV of onboarding and account activation status.
     *
     * @param  Closure(): bool  $isVisible
     */
    public static function exportActivationStatusAction(Closure $isVisible): Action
    {
        return Action::make('exportActivationStatus')
            ->label('Export activation status (.csv)')
            ->color('gray')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible($isVisible)
            ->authorize($isVisible)
            ->action(function (mixed $livewire): BinaryFileResponse {
                $profiles = self::scopedDirectoryProfiles($livewire);

                $exportService = app(OngoingStudentSpreadsheetExportService::class);
                $path = $exportService->exportActivationStatus($profiles);

                return $exportService->downloadAndDelete($path, basename($path), 'csv');
            });
    }

    /**
     * Table bulk action to export ONLY selected student rows as a formula-safe CSV.
     */
    public static function exportSelectedStudentsBulkAction(): BulkAction
    {
        $isRegistrar = static fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;

        return BulkAction::make('exportSelectedStudents')
            ->label('Export selected (.csv)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible($isRegistrar)
            ->authorize($isRegistrar)
            ->action(function (Collection $records, mixed $livewire): BinaryFileResponse {
                $profiles = $records
                    ->filter(fn (mixed $record): bool => $record instanceof StudentProfile)
                    ->values();
                $termId = self::activeEnrollmentTermId($livewire);

                $exportService = app(OngoingStudentSpreadsheetExportService::class);
                $path = $exportService->exportStudentDirectory($profiles, $termId, 'selected-students');

                return $exportService->downloadAndDelete($path, basename($path), 'csv');
            });
    }

    /**
     * Table bulk action to send 7-day activation invites to selected student profiles.
     */
    public static function sendSelectedActivationInvitesBulkAction(): BulkAction
    {
        $isRegistrar = static fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;

        return BulkAction::make('sendSelectedActivationInvites')
            ->label('Send activation invites')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('primary')
            ->visible($isRegistrar)
            ->authorize($isRegistrar)
            ->requiresConfirmation()
            ->modalHeading('Send activation invites to selected students')
            ->modalDescription(function (Collection $records): string {
                $eligibleCount = $records
                    ->filter(fn (mixed $record): bool => $record instanceof StudentProfile && self::isEligibleForInvite($record))
                    ->count();
                $skippedCount = $records->count() - $eligibleCount;

                if ($eligibleCount === 0) {
                    return 'None of the '.$records->count().' selected '.Str::plural('student', $records->count()).' need an activation invite (all selected accounts are already activated or lack a sign-in email).';
                }

                $summary = "Send 7-day Servitech Student Hub activation links to {$eligibleCount} selected ".Str::plural('student', $eligibleCount).'.';
                if ($skippedCount > 0) {
                    $summary .= " {$skippedCount} already-activated ".Str::plural('account', $skippedCount).' will be skipped.';
                }

                return $summary;
            })
            ->modalSubmitActionLabel('Send invites')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $profiles = $records
                    ->filter(fn (mixed $record): bool => $record instanceof StudentProfile)
                    ->values();

                self::dispatchBulkInvites($profiles, $actor);
            });
    }

    /**
     * Build chronologically sorted "Completed Through Term" options for a curriculum version.
     *
     * @return array<string, string>
     */
    public static function completedThroughOptions(mixed $curriculumVersionId): array
    {
        if (blank($curriculumVersionId)) {
            return [];
        }

        $entries = CurriculumEntry::query()
            ->where('curriculum_version_id', (int) $curriculumVersionId)
            ->with('courseSpecification')
            ->orderBy('year_level')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            return [];
        }

        $grouped = $entries
            ->filter(fn (CurriculumEntry $entry): bool => self::parseYearNumber($entry->year_level) !== null && filled($entry->term_type))
            ->sortBy(fn (CurriculumEntry $entry): array => [
                self::parseYearNumber($entry->year_level) ?? 99,
                self::termTypeRank($entry->term_type),
                (int) $entry->sequence,
                (int) $entry->id,
            ])
            ->groupBy(fn (CurriculumEntry $entry): string => (self::parseYearNumber($entry->year_level) ?? 0).'|'.$entry->term_type);

        $options = [];
        $runningSubjects = 0;
        $runningUnits = 0.0;

        foreach ($grouped as $key => $groupEntries) {
            /** @var CurriculumEntry $first */
            $first = $groupEntries->first();
            $yearNumber = self::parseYearNumber($first->year_level);
            if ($yearNumber === null) {
                continue;
            }

            $runningSubjects += $groupEntries->count();
            $runningUnits += $groupEntries->sum(fn (CurriculumEntry $entry): float => (float) ($entry->courseSpecification?->credit_units ?? 0));

            $termLabel = filled($first->term_label)
                ? (string) $first->term_label
                : self::termLabelForType($first->term_type);
            $formattedUnits = rtrim(rtrim(number_format($runningUnits, 2, '.', ''), '0'), '.');

            $options[$key] = "Through Year {$yearNumber} · {$termLabel} ({$runningSubjects} ".Str::plural('subject', $runningSubjects)." · {$formattedUnits} units)";
        }

        return $options;
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    public static function parseCompletedThroughValue(string $value): array
    {
        $parts = explode('|', trim($value), 2);
        if (count($parts) !== 2) {
            return [null, null];
        }

        $year = ctype_digit($parts[0]) ? (int) $parts[0] : null;
        $termType = trim($parts[1]);

        if ($year === null || $year < 1 || ! in_array($termType, [Term::TypeFirstSemester, Term::TypeSecondSemester, Term::TypeSpecialTerm], true)) {
            return [null, null];
        }

        return [$year, $termType];
    }

    /**
     * @return array<int, string>
     */
    private static function programOptions(): array
    {
        return Program::query()
            ->whereHas('curriculumVersions', fn (Builder $query): Builder => $query->whereIn('state', [
                CurriculumVersion::StateActive,
                CurriculumVersion::StateSuperseded,
            ]))
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Program $program): array => [
                $program->id => StudentProfileResource::academicProgramLabel($program)
                    .($program->is_active ? '' : ' · (Teach-out / Inactive)'),
            ])
            ->all();
    }

    private static function resolveDefaultCurriculumId(int $programId, ?int $entryAcademicYearId): ?int
    {
        $program = Program::query()->find($programId);
        if (! $program instanceof Program) {
            return null;
        }

        if ($entryAcademicYearId !== null) {
            $batchCurriculum = $program->curriculumForAcademicYear($entryAcademicYearId);
            if ($batchCurriculum instanceof CurriculumVersion) {
                return (int) $batchCurriculum->id;
            }

            if ($program->curriculumVersionBatches()->exists()) {
                return null;
            }
        }

        $active = CurriculumVersion::query()
            ->where('program_id', $programId)
            ->where('state', CurriculumVersion::StateActive)
            ->orderByDesc('id')
            ->get();

        if ($active->count() === 1) {
            return (int) $active->sole()->id;
        }

        if ($active->isEmpty()) {
            $superseded = CurriculumVersion::query()
                ->where('program_id', $programId)
                ->where('state', CurriculumVersion::StateSuperseded)
                ->orderByDesc('id')
                ->get();

            if ($superseded->count() === 1) {
                return (int) $superseded->sole()->id;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function curriculumOptions(mixed $programId): array
    {
        if (blank($programId)) {
            return [];
        }

        $curricula = CurriculumVersion::query()
            ->where('program_id', (int) $programId)
            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
            ->with(['academicYears', 'batches.academicYear'])
            ->get();

        return CurriculumVersion::sortByLatestBatch($curricula, groupByState: true)
            ->mapWithKeys(function (CurriculumVersion $cv): array {
                $batchLabel = $cv->academicYears->isNotEmpty() ? $cv->batchLabel() : null;
                $displayName = filled($cv->name) ? trim((string) $cv->name) : null;

                if ($batchLabel !== null && $displayName !== null) {
                    $displayName = trim((string) preg_replace('/(?:\s*·\s*Batch\s+.*$|^Batch\s+.*$)/iu', '', $displayName));

                    if ($displayName === '' || strcasecmp($displayName, $batchLabel) === 0) {
                        $displayName = null;
                    }
                }

                return [
                    $cv->id => collect([
                        $cv->version_code,
                        $displayName,
                        $batchLabel,
                        $cv->state === CurriculumVersion::StateSuperseded ? '(Retired)' : null,
                    ])->filter()->implode(' · '),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function curriculumEntryOptions(mixed $curriculumId): array
    {
        if (blank($curriculumId)) {
            return [];
        }

        return CurriculumEntry::query()
            ->where('curriculum_version_id', (int) $curriculumId)
            ->with('courseSpecification.course')
            ->orderBy('year_level')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(function (CurriculumEntry $entry): array {
                $code = $entry->courseSpecification?->course?->code;
                $title = $entry->courseSpecification?->title;
                $period = collect([
                    filled($entry->year_level) ? 'Year '.$entry->year_level : null,
                    $entry->term_label,
                ])->filter()->implode(' · ');

                $label = collect([$code, $title])->filter()->implode(' · ')
                    .($period !== '' ? " ({$period})" : '');

                return [$entry->id => $label];
            })
            ->all();
    }

    private static function activeProgramFilterId(mixed $livewire): ?int
    {
        if (! is_object($livewire) || ! property_exists($livewire, 'tableFilters') || ! is_array($livewire->tableFilters)) {
            return null;
        }

        $value = $livewire->tableFilters['program']['value'] ?? $livewire->tableFilters['program_id']['value'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private static function activeEnrollmentTermId(mixed $livewire): ?int
    {
        if (! is_object($livewire) || ! property_exists($livewire, 'tableFilters') || ! is_array($livewire->tableFilters)) {
            return null;
        }

        $value = $livewire->tableFilters['enrollment_scope']['term_id'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private static function directoryExportPrefix(mixed $livewire): string
    {
        $parts = ['student-records'];

        $programId = self::activeProgramFilterId($livewire);
        if ($programId !== null) {
            $programCode = Program::query()->whereKey($programId)->value('code');
            if (is_string($programCode) && trim($programCode) !== '') {
                $parts[] = Str::slug($programCode);
            }
        }

        if (is_object($livewire) && property_exists($livewire, 'tableFilters') && is_array($livewire->tableFilters)) {
            $entryAy = $livewire->tableFilters['entry_year']['value'] ?? null;
            if (is_numeric($entryAy)) {
                $ayLabel = AcademicYear::query()->whereKey((int) $entryAy)->value('label');
                if (is_string($ayLabel) && trim($ayLabel) !== '') {
                    $parts[] = Str::slug($ayLabel);
                }
            }
        }

        return implode('-', $parts);
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    private static function scopedDirectoryProfiles(mixed $livewire): Collection
    {
        $query = ($livewire instanceof HasTable && method_exists($livewire, 'getFilteredTableQuery'))
            ? clone $livewire->getFilteredTableQuery()
            : StudentProfileResource::getEloquentQuery();

        return $query
            ->with(['user', 'program', 'curriculumVersion'])
            ->orderBy('student_number')
            ->get();
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    private static function eligiblePendingProfiles(mixed $livewire): Collection
    {
        return self::scopedDirectoryProfiles($livewire)
            ->filter(fn (StudentProfile $profile): bool => self::isEligibleForInvite($profile))
            ->values();
    }

    private static function isEligibleForInvite(StudentProfile $profile): bool
    {
        $profile->loadMissing('user');

        return $profile->user instanceof User
            && filled($profile->user->email)
            && StudentAccountActivation::canResendActivation($profile);
    }

    /**
     * @param  Collection<int, StudentProfile>  $profiles
     */
    private static function dispatchBulkInvites(Collection $profiles, User $actor): void
    {
        $activation = app(StudentAccountActivation::class);
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($profiles as $profile) {
            if (! $profile instanceof StudentProfile) {
                continue;
            }

            if (! self::isEligibleForInvite($profile)) {
                $skipped++;

                continue;
            }

            try {
                if ($activation->sendActivationEmail($profile, $actor)) {
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        if ($sent === 0 && $failed === 0) {
            Notification::make()
                ->title('No activation invites sent')
                ->body('All selected students have already completed account activation or lack a sign-in email.')
                ->warning()
                ->send();

            return;
        }

        $bodyParts = ["Sent {$sent} 7-day activation ".Str::plural('invite', $sent).'.'];
        if ($skipped > 0) {
            $bodyParts[] = "Skipped {$skipped} already-activated ".Str::plural('account', $skipped).'.';
        }
        if ($failed > 0) {
            $bodyParts[] = "{$failed} ".Str::plural('invite', $failed).' failed delivery; review mail logs.';
        }

        Notification::make()
            ->title($failed > 0 ? 'Activation invites sent with warnings' : 'Activation invites sent')
            ->body(implode(' ', $bodyParts))
            ->status($failed > 0 ? 'warning' : 'success')
            ->send();
    }

    private static function parseYearNumber(mixed $yearLevel): ?int
    {
        if (is_numeric($yearLevel)) {
            $num = (int) $yearLevel;

            return $num > 0 ? $num : null;
        }

        $raw = strtolower(trim((string) ($yearLevel ?? '')));
        if (preg_match('/(\d+)/', $raw, $matches) === 1) {
            $num = (int) $matches[1];

            return $num > 0 ? $num : null;
        }

        return match (true) {
            str_contains($raw, 'first') => 1,
            str_contains($raw, 'second') => 2,
            str_contains($raw, 'third') => 3,
            str_contains($raw, 'fourth') => 4,
            str_contains($raw, 'fifth') => 5,
            default => null,
        };
    }

    private static function termTypeRank(?string $termType): int
    {
        return match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => 9,
        };
    }

    private static function termLabelForType(?string $termType): string
    {
        return match ($termType) {
            Term::TypeFirstSemester => 'First Semester',
            Term::TypeSecondSemester => 'Second Semester',
            Term::TypeSpecialTerm => 'Summer',
            default => 'Prior Term',
        };
    }
}
