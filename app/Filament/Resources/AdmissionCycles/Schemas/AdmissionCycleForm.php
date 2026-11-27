<?php

namespace App\Filament\Resources\AdmissionCycles\Schemas;

use App\Filament\Resources\AdmissionCycles\Pages\EditAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\RelationManagers\RequirementSetsRelationManager;
use App\Models\AdmissionCycle;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Component;

class AdmissionCycleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('Cycle details & schedule')
                    ->description(fn (Get $get): string => self::detailsStepSummary($get))
                    ->icon('heroicon-o-calendar-days')
                    ->afterValidation(function (Component $livewire): void {
                        if ($livewire instanceof EditAdmissionCycle) {
                            $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);
                        }
                    })
                    ->schema([
                        Section::make('Cycle identity')
                            ->compact()
                            ->description('Your logged-in Registrar account and institutional privacy/support details are applied automatically.')
                            ->schema([
                                TextInput::make('label')
                                    ->label('Applicant-facing name')
                                    ->placeholder('First semester admissions 2026–2027')
                                    ->helperText('The name applicants see when choosing an admission cycle.')
                                    ->required()
                                    ->maxLength(160),
                                TextInput::make('code')
                                    ->label('Internal cycle code')
                                    ->placeholder('AY2026-2027-SEM1')
                                    ->helperText('A unique reference for staff and retained records. It is reserved only once the cycle is published.')
                                    ->required()
                                    ->maxLength(40)
                                    ->extraInputAttributes([
                                        'autocorrect' => 'off',
                                        'autocapitalize' => 'characters',
                                        'spellcheck' => 'false',
                                    ])
                                    ->rules([
                                        fn (?AdmissionCycle $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                            if (is_string($value) && filled($value) && AdmissionCycle::codeIsReservedByAnotherCycle($value, $record?->id)) {
                                                $fail(AdmissionCycle::CodeReservedMessage);
                                            }
                                        },
                                    ]),
                                Select::make('term_id')
                                    ->label('Academic Year')
                                    ->validationAttribute('Academic Year')
                                    ->placeholder('Choose the academic year and semester applicants will enter')
                                    ->helperText('Selecting an academic year automatically detects programs with an open curriculum batch for that school year.')
                                    ->relationship(
                                        name: 'term',
                                        titleAttribute: 'label',
                                        modifyQueryUsing: fn (Builder $query, ?AdmissionCycle $record): Builder => $query
                                            ->with('academicYear')
                                            ->where(function (Builder $terms) use ($record): void {
                                                $terms->where('terms.state', '!=', Term::StateClosed)
                                                    ->whereHas('academicYear', fn (Builder $ay): Builder => $ay->operational());

                                                if ($record instanceof AdmissionCycle && $record->term_id !== null) {
                                                    $terms->orWhere('terms.id', $record->term_id);
                                                }
                                            })
                                            ->orderByDesc('terms.starts_on'),
                                    )
                                    ->getOptionLabelFromRecordUsing(fn (Term $record): string => self::academicYearOptionLabel($record))
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function (?int $state, Set $set, ?AdmissionCycle $record): void {
                                        if ($state === null) {
                                            return;
                                        }

                                        $eligibleProgramIds = self::eligibleProgramIdsForTerm($state, $record);

                                        if ($eligibleProgramIds !== []) {
                                            $set('programs', $eligibleProgramIds);
                                        }
                                    })
                                    ->required()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Section::make('Application window')
                            ->compact()
                            ->description('Type or pick dates in Philippine Time (Asia/Manila). New applications close stops new submissions; corrections remain open until the correction deadline.')
                            ->schema([
                                DateTimePicker::make('opens_at')
                                    ->label('Applications open')
                                    ->validationAttribute('Applications open')
                                    ->timezone('Asia/Manila')
                                    ->seconds(true)
                                    ->required()
                                    ->helperText('When applicants can start and submit applications.'),
                                DateTimePicker::make('closes_at')
                                    ->label('New applications close')
                                    ->validationAttribute('New applications close')
                                    ->after(fn (Get $get): ?string => filled($get('opens_at')) ? 'opens_at' : null)
                                    ->validationMessages([
                                        'after' => 'New applications close must be after Applications open.',
                                    ])
                                    ->timezone('Asia/Manila')
                                    ->seconds(true)
                                    ->required()
                                    ->helperText('Stops new applicants from starting or submitting an application.'),
                                DateTimePicker::make('correction_closes_at')
                                    ->label('Correction request deadline')
                                    ->validationAttribute('Correction request deadline')
                                    ->afterOrEqual(fn (Get $get): ?string => filled($get('closes_at')) ? 'closes_at' : null)
                                    ->validationMessages([
                                        'after_or_equal' => 'Correction request deadline must be on or after New applications close.',
                                    ])
                                    ->timezone('Asia/Manila')
                                    ->seconds(true)
                                    ->required()
                                    ->helperText('Last date the Registrar can request corrections on submitted applications.'),
                            ])
                            ->columns(['default' => 1, 'lg' => 3]),
                        Section::make('Post-admission deadlines')
                            ->compact()
                            ->description('Admitted applicants who miss the papers or payment deadline are marked Expired. After the final admission cutoff, unfinished applications are permanently closed.')
                            ->schema([
                                DateTimePicker::make('requirements_due_at')
                                    ->label('Physical papers due')
                                    ->validationAttribute('Physical papers due')
                                    ->afterOrEqual(fn (Get $get): ?string => filled($get('closes_at')) ? 'closes_at' : null)
                                    ->beforeOrEqual(fn (Get $get): ?string => filled($get('period_ends_at')) ? 'period_ends_at' : null)
                                    ->validationMessages([
                                        'after_or_equal' => 'Physical papers due must be on or after New applications close.',
                                        'before_or_equal' => 'Physical papers due must be on or before the Final admission cutoff.',
                                    ])
                                    ->timezone('Asia/Manila')
                                    ->seconds(true)
                                    ->required()
                                    ->helperText('Deadline for admitted applicants to submit physical papers.'),
                                DateTimePicker::make('payment_due_at')
                                    ->label('Enrollment payment due')
                                    ->validationAttribute('Enrollment payment due')
                                    ->afterOrEqual(fn (Get $get): ?string => filled($get('closes_at')) ? 'closes_at' : null)
                                    ->beforeOrEqual(fn (Get $get): ?string => filled($get('period_ends_at')) ? 'period_ends_at' : null)
                                    ->validationMessages([
                                        'after_or_equal' => 'Enrollment payment due must be on or after New applications close.',
                                        'before_or_equal' => 'Enrollment payment due must be on or before the Final admission cutoff.',
                                    ])
                                    ->timezone('Asia/Manila')
                                    ->seconds(true)
                                    ->required()
                                    ->helperText('Deadline for cleared applicants to complete enrollment payment.'),
                                DateTimePicker::make('period_ends_at')
                                    ->label('Final admission cutoff')
                                    ->validationAttribute('Final admission cutoff')
                                    ->afterOrEqual(fn (Get $get): ?string => filled($get('closes_at')) ? 'closes_at' : null)
                                    ->validationMessages([
                                        'after_or_equal' => 'Final admission cutoff must be on or after New applications close.',
                                    ])
                                    ->timezone('Asia/Manila')
                                    ->seconds(true)
                                    ->required()
                                    ->helperText('After this date, all unfinished applications are permanently closed.'),
                            ])
                            ->columns(['default' => 1, 'lg' => 3]),
                    ]),
                Step::make('Programs & student types')
                    ->description(fn (Get $get): string => self::programsStepSummary($get))
                    ->icon('heroicon-o-academic-cap')
                    ->schema([
                        Section::make('Who can apply')
                            ->compact()
                            ->description('Programs are auto-detected from active programs with a published curriculum assigned for the target school year batch.')
                            ->schema([
                                CheckboxList::make('accepted_paths')
                                    ->label('Student types')
                                    ->options(AdmissionCycle::studentTypeOptions())
                                    ->default([AdmissionCycle::PathFirstYear, AdmissionCycle::PathTransferee])
                                    ->required()
                                    ->validationMessages([
                                        'required' => 'Select at least one student type (Freshman or Transferee).',
                                    ])
                                    ->dehydrated(false),
                                Select::make('programs')
                                    ->label('Accepting programs')
                                    ->placeholder('Select eligible programs')
                                    ->relationship(
                                        name: 'programs',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query, ?AdmissionCycle $record): Builder => $query
                                            ->with(['curriculumVersions.academicYears'])
                                            ->where(function (Builder $programs) use ($record): void {
                                                $programs->where('programs.is_active', true);

                                                if ($record instanceof AdmissionCycle) {
                                                    $programs->orWhereHas('admissionCycles', fn (Builder $cycles): Builder => $cycles->where('admission_cycles.id', $record->id));
                                                }
                                            })
                                            ->orderBy('programs.name'),
                                    )
                                    ->getOptionLabelFromRecordUsing(function (Program $record, Get $get): string {
                                        $availability = self::programAvailabilityForTerm($record, $get('term_id'), null);

                                        if ($availability['available']) {
                                            return $record->name;
                                        }

                                        return "{$record->name} — {$availability['reason']}";
                                    })
                                    ->disableOptionWhen(function (string $value, Get $get, ?AdmissionCycle $record): bool {
                                        if (! CurriculumVersion::query()->exists()) {
                                            return false;
                                        }

                                        $program = Program::query()->with(['curriculumVersions.academicYears'])->find((int) $value);

                                        if (! $program instanceof Program) {
                                            return false;
                                        }

                                        return ! self::programAvailabilityForTerm($program, $get('term_id'), $record)['available'];
                                    })
                                    ->multiple()
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->validationMessages([
                                        'required' => 'Select at least one eligible accepting program.',
                                    ])
                                    ->helperText('Inactive programs or programs without an assigned curriculum for the target school year batch are disabled automatically.')
                                    ->saveRelationshipsUsing(function (AdmissionCycle $record, array $state, Get $get): void {
                                        $paths = (array) $get('accepted_paths');
                                        $record->programs()->sync(collect($state)->mapWithKeys(fn (int|string $programId): array => [
                                            (int) $programId => [
                                                'accepts_first_year' => in_array(AdmissionCycle::PathFirstYear, $paths, true),
                                                'accepts_transferee' => in_array(AdmissionCycle::PathTransferee, $paths, true),
                                            ],
                                        ])->all());
                                    }),
                                Placeholder::make('requirements_note')
                                    ->label('Requirements')
                                    ->content('After you save, you set the Freshman and Transferee requirements right here in this step.')
                                    ->visible(fn ($livewire): bool => ! $livewire instanceof EditAdmissionCycle)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Livewire::make(
                            RequirementSetsRelationManager::class,
                            fn ($livewire): array => $livewire instanceof EditAdmissionCycle ? $livewire->requirementSetsManagerProperties() : [],
                        )
                            ->key('requirement-sets')
                            ->visible(fn ($livewire): bool => $livewire instanceof EditAdmissionCycle)
                            ->columnSpanFull(),
                    ]),
            ])
                ->skippable(fn (?AdmissionCycle $record): bool => $record instanceof AdmissionCycle)
                ->persistStepInQueryString()
                ->extraAttributes(['class' => 'tala-cycle-wizard'])
                ->nextAction(fn (Action $action): Action => $action->label('Save & continue'))
                ->cancelAction(view('filament.admin.admission-cycles.wizard-cancel'))
                ->submitAction(view('filament.admin.admission-cycles.wizard-submit'))
                ->columnSpanFull(),
        ]);
    }

    /**
     * Stage-bar summary for the details step (#110 item 13), e.g. "Opens Jan 5 · Closes Feb 1".
     */
    public static function detailsStepSummary(Get $get): string
    {
        $opensAt = $get('opens_at');
        $closesAt = $get('closes_at');

        if (blank($opensAt) || blank($closesAt)) {
            return 'Name the intake, pick the academic year, and set application dates.';
        }

        $format = fn (mixed $value): string => CarbonImmutable::parse((string) $value, 'Asia/Manila')->format('M j, Y');

        return 'Opens '.$format($opensAt).' · Closes '.$format($closesAt);
    }

    /**
     * Stage-bar summary for the programs step (#110 item 13), e.g. "2 student types · 3 programs".
     */
    public static function programsStepSummary(Get $get): string
    {
        $pathCount = count(array_filter((array) $get('accepted_paths')));
        $programCount = count(array_filter((array) $get('programs')));

        if ($pathCount === 0 && $programCount === 0) {
            return 'Confirm which student types and eligible programs accept applicants.';
        }

        return $pathCount.' '.Str::plural('student type', $pathCount).' · '.$programCount.' '.Str::plural('program', $programCount);
    }

    /**
     * Academic Year first, then the semester (#110 item 9): "AY 2026–2027 · First Semester".
     * A term label that already names its year (e.g. "AY 2026-2027 - First Semester") is not repeated.
     */
    public static function academicYearOptionLabel(Term $term): string
    {
        $yearLabel = trim((string) $term->academicYear?->label);
        $termLabel = trim((string) $term->label);

        if ($yearLabel === '') {
            return $termLabel;
        }

        $yearDigits = preg_replace('/^AY\s*/i', '', $yearLabel);
        $semester = trim((string) preg_replace('/^(AY\s*)?'.preg_quote($yearDigits, '/').'\s*[-–·:]?\s*/i', '', $termLabel));
        $displayYear = preg_match('/^\d/', $yearLabel) === 1 ? 'AY '.$yearLabel : $yearLabel;

        return collect([$displayYear, $semester])->filter()->implode(' · ');
    }

    /**
     * @return list<int>
     */
    public static function eligibleProgramIdsForTerm(int $termId, ?AdmissionCycle $record = null): array
    {
        $hasConfiguredCurricula = CurriculumVersion::query()->exists();
        $syntheticCycle = self::syntheticCycleForTerm($termId, $record);

        return Program::query()
            ->where('is_active', true)
            ->with(['curriculumVersions.academicYears'])
            ->orderBy('name')
            ->get()
            ->filter(function (Program $program) use ($syntheticCycle, $hasConfiguredCurricula): bool {
                if (! $hasConfiguredCurricula) {
                    return true;
                }

                return $program->admissionsAvailability($syntheticCycle)['available'];
            })
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array{available: bool, status: string, reason: string, batch_label: ?string, curriculum_id: ?int}
     */
    private static function programAvailabilityForTerm(Program $program, mixed $termId, ?AdmissionCycle $record): array
    {
        if (! CurriculumVersion::query()->exists()) {
            return [
                'available' => (bool) $program->is_active,
                'status' => $program->is_active ? 'Available' : 'Inactive',
                'reason' => $program->is_active ? 'Available in Admissions' : 'Program is inactive',
                'batch_label' => null,
                'curriculum_id' => null,
            ];
        }

        $resolvedTermId = is_numeric($termId) ? (int) $termId : $record?->term_id;

        if ($resolvedTermId === null) {
            return [
                'available' => (bool) $program->is_active,
                'status' => $program->is_active ? 'Available' : 'Inactive',
                'reason' => $program->is_active ? 'Available in Admissions' : 'Program is inactive',
                'batch_label' => null,
                'curriculum_id' => null,
            ];
        }

        return $program->admissionsAvailability(self::syntheticCycleForTerm($resolvedTermId, $record));
    }

    private static function syntheticCycleForTerm(int $termId, ?AdmissionCycle $record): AdmissionCycle
    {
        $term = Term::query()->with('academicYear')->find($termId);

        $cycle = $record instanceof AdmissionCycle ? clone $record : new AdmissionCycle;
        $cycle->term_id = $termId;

        if ($term instanceof Term) {
            $cycle->setRelation('term', $term);
        }

        return $cycle;
    }
}
