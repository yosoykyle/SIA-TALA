<?php

namespace App\Filament\Resources\AdmissionCycles\RelationManagers;

use App\Actions\Admissions\PublishAdmissionRequirementSet;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequirementSetsRelationManager extends RelationManager
{
    public const PathBoth = 'Both';

    public const ModeBothOnlineAndF2f = 'both_online_and_f2f';

    public const ModeOnlineOnly = 'online_only';

    public const ModeF2fOnly = 'f2f_only';

    public const ModeSchoolToSchoolWithOnline = 'school_to_school_with_online';

    public const ModeSchoolToSchoolOnly = 'school_to_school_only';

    protected static bool $isLazy = false;

    protected static string $relationship = 'requirementSets';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Requirement list details')
                    ->description('Version numbering and replacement links are managed automatically.')
                    ->schema([
                        Radio::make('application_path')
                            ->label('Student type')
                            ->options(fn (?AdmissionRequirementSet $record): array => $record instanceof AdmissionRequirementSet
                                ? AdmissionCycle::studentTypeOptions()
                                : [
                                    ...AdmissionCycle::studentTypeOptions(),
                                    self::PathBoth => 'Both Freshman & Transferee',
                                ])
                            ->default(AdmissionCycle::PathFirstYear)
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                $set('requirements', self::withMainCredentialFor((array) $get('requirements'), (string) $state));
                            })
                            ->descriptions(fn (?AdmissionRequirementSet $record): array => $record instanceof AdmissionRequirementSet
                                ? []
                                : [
                                    self::PathBoth => 'Creates matching Draft requirement lists for both Freshman and Transferee.',
                                ])
                            ->inline()
                            ->inlineLabel(false)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Repeater::make('requirements')
                    ->label('Requirements')
                    ->relationship()
                    ->default(fn (): array => [self::mainCredentialRow(AdmissionCycle::PathFirstYear)])
                    ->rules([
                        fn (Get $get, ?AdmissionRequirementSet $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                            $path = $record instanceof AdmissionRequirementSet ? $record->application_path : (string) $get('application_path');
                            $problem = self::mainCredentialProblem(collect((array) $value)->pluck('credential_classification')->all(), $path);

                            if ($problem !== null) {
                                $fail($problem);
                            }
                        },
                    ])
                    ->itemLabel(fn (array $state): ?string => filled($state['label'] ?? null) ? (string) $state['label'] : 'New requirement')
                    ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                        $data['submission_mode'] = self::resolveSubmissionMode(
                            (bool) ($data['requires_preliminary_evidence'] ?? false),
                            (string) ($data['official_submission_method'] ?? AdmissionRequirement::SubmissionInPerson),
                        );

                        return $data;
                    })
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::prepareRequirementPayload($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::prepareRequirementPayload($data))
                    ->schema([
                        TextInput::make('label')
                            ->label('Requirement name')
                            ->placeholder('e.g., PSA Birth Certificate, Form 138, 2x2 ID Photo')
                            ->required()
                            ->maxLength(160)
                            ->columnSpanFull(),
                        Textarea::make('purpose')
                            ->label('Purpose')
                            ->placeholder('Briefly explain why this document is needed.')
                            ->required()
                            ->maxLength(1000)
                            ->rows(2)
                            ->columnSpanFull(),
                        Radio::make('credential_classification')
                            ->label('Requirement type')
                            ->options(fn (Get $get): array => self::requirementTypeOptions($get('credential_classification')))
                            ->descriptions(fn (Get $get): array => self::requirementTypeDescriptions($get('credential_classification')))
                            ->default(AdmissionRequirement::ClassificationCoreOtherOfficialCredential)
                            ->disabled(fn (Get $get): bool => self::isMainCredential($get('credential_classification')))
                            ->dehydrated()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                $canBeHeld = $state === AdmissionRequirement::ClassificationNonCore;
                                $set('exception_permitted', $canBeHeld);

                                if (! $canBeHeld) {
                                    $set('required_approving_authority', null);
                                }
                            })
                            ->columnSpanFull(),
                        Select::make('due_stage')
                            ->label('When it is due')
                            ->options([
                                AdmissionRequirement::DuePreliminaryReview => 'During application review',
                                AdmissionRequirement::DueEnrollmentReadiness => 'Before enrollment (Enrollment readiness)',
                                AdmissionRequirement::DuePostEnrollmentFollowUp => 'After enrollment (Follow-up)',
                            ])
                            ->default(AdmissionRequirement::DueEnrollmentReadiness)
                            ->required(),
                        Radio::make('submission_mode')
                            ->label('How applicants submit this')
                            ->options([
                                self::ModeBothOnlineAndF2f => 'Both online upload and face-to-face (In person)',
                                self::ModeOnlineOnly => 'Online upload only',
                                self::ModeF2fOnly => 'Face-to-face only (In person)',
                                self::ModeSchoolToSchoolWithOnline => 'Online upload + School-to-school official record',
                                self::ModeSchoolToSchoolOnly => 'School-to-school official record only',
                            ])
                            ->default(self::ModeBothOnlineAndF2f)
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('applicant_instructions')
                            ->label('Instructions for applicants')
                            ->placeholder('Explain what file or physical copy the applicant needs to prepare.')
                            ->required()
                            ->maxLength(1500)
                            ->rows(2)
                            ->columnSpanFull(),
                        Textarea::make('registrar_instructions')
                            ->label('Verification notes for Registrar')
                            ->placeholder('Explain what the Registrar checks when reviewing this item.')
                            ->required()
                            ->maxLength(1500)
                            ->rows(2)
                            ->columnSpanFull(),
                        TextInput::make('required_approving_authority')
                            ->label('Who approves a hold')
                            ->placeholder('e.g., Registrar')
                            ->helperText('The applicant may be cleared while this paper is still owed, with this approval and a due date.')
                            ->required(fn (Get $get): bool => $get('credential_classification') === AdmissionRequirement::ClassificationNonCore)
                            ->visible(fn (Get $get): bool => $get('credential_classification') === AdmissionRequirement::ClassificationNonCore)
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->reorderable()
                    ->orderColumn('display_order')
                    ->collapsible()
                    ->cloneable()
                    ->addActionLabel('Add requirement')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('application_path')
            ->columns([
                TextColumn::make('application_path')
                    ->label('Student type')
                    ->formatStateUsing(fn (string $state): string => AdmissionCycle::studentTypeLabel($state))
                    ->badge()
                    ->color(fn (string $state): string|array => AdmissionCycle::studentTypeColor($state)),
                TextColumn::make('version')
                    ->label('Version')
                    ->prefix('v')
                    ->sortable(),
                TextColumn::make('state')->badge(),
                TextColumn::make('requirements_count')->counts('requirements')->label('Requirements'),
                TextColumn::make('published_at')
                    ->dateTime('M j, Y · g:i A')
                    ->timezone('Asia/Manila')
                    ->placeholder('Not published'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add requirement list')
                    ->modalHeading('Create requirement list')
                    ->modalWidth(Width::FourExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->visible(fn (): bool => $this->getOwnerRecord()->state !== AdmissionCycle::StateCancelled)
                    ->mutateDataUsing(function (array $data): array {
                        /** @var AdmissionCycle $cycle */
                        $cycle = $this->getOwnerRecord();
                        $selectedPath = (string) ($data['application_path'] ?? AdmissionCycle::PathFirstYear);
                        $primaryPath = $selectedPath === self::PathBoth
                            ? AdmissionCycle::PathFirstYear
                            : $selectedPath;

                        $latestPublished = AdmissionRequirementSet::query()
                            ->where('admission_cycle_id', $cycle->id)
                            ->where('application_path', $primaryPath)
                            ->where('state', AdmissionRequirementSet::StatePublished)
                            ->latest('version')
                            ->first();

                        $nextVersion = ((int) AdmissionRequirementSet::query()
                            ->where('admission_cycle_id', $cycle->id)
                            ->where('application_path', $primaryPath)
                            ->max('version')) + 1;

                        $data['application_path'] = $primaryPath;
                        $data['version'] = $nextVersion;
                        // #110: a requirement list takes effect when it is created; submitted applications keep their original requirements.
                        $data['effective_at'] = now(config('app.timezone'));
                        $data['replaces_requirement_set_id'] = $latestPublished?->id;

                        return $data;
                    })
                    ->after(function (AdmissionRequirementSet $record, CreateAction $action): void {
                        if (($action->getRawData()['application_path'] ?? null) !== self::PathBoth) {
                            return;
                        }

                        /** @var AdmissionCycle $cycle */
                        $cycle = $this->getOwnerRecord();
                        $record->loadMissing('requirements');

                        DB::transaction(function () use ($cycle, $record): void {
                            $rawRequirements = $record->requirements
                                ->sortBy('display_order')
                                ->values()
                                ->map(fn (AdmissionRequirement $requirement): array => [
                                    'label' => $requirement->label,
                                    'authority_reference' => $requirement->authority_reference,
                                    'purpose' => $requirement->purpose,
                                    'credential_classification' => $requirement->credential_classification,
                                    'submission_mode' => self::resolveSubmissionMode(
                                        (bool) $requirement->requires_preliminary_evidence,
                                        (string) $requirement->official_submission_method,
                                    ),
                                    'due_stage' => $requirement->due_stage,
                                    'applicant_instructions' => $requirement->applicant_instructions,
                                    'registrar_instructions' => $requirement->registrar_instructions,
                                    'exception_permitted' => (bool) $requirement->exception_permitted,
                                    'required_approving_authority' => $requirement->required_approving_authority,
                                ])
                                ->all();

                            self::createSetForPath(
                                cycle: $cycle,
                                path: AdmissionCycle::PathTransferee,
                                effectiveAt: $record->effective_at ?? now(config('app.timezone')),
                                rawRequirements: $rawRequirements,
                                adaptCoreForTargetPath: true,
                            );
                        });
                    }),
            ])
            ->emptyStateHeading('No requirement lists yet')
            ->emptyStateDescription('Add a requirement list for Freshman, Transferee, or both student types before publishing this cycle.')
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->recordActions([
                Action::make('publish')
                    ->label('Publish')
                    ->modalSubmitActionLabel('Publish requirement list')
                    ->icon('heroicon-o-check-badge')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalWidth(Width::Large)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->modalDescription('Publishing locks this requirement list for incoming applicants. Review each item and submission mode first.')
                    ->visible(fn (AdmissionRequirementSet $record): bool => $record->state === AdmissionRequirementSet::StateDraft
                        && $this->getOwnerRecord()->state !== AdmissionCycle::StateCancelled)
                    ->action(function (AdmissionRequirementSet $record): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);

                        try {
                            app(PublishAdmissionRequirementSet::class)->execute(
                                $record,
                                $actor,
                                null,
                                $record->replacedRequirementSet,
                            );
                            Notification::make()->title('Requirement list published')->success()->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Requirement list cannot be published')
                                ->body($exception->validator->errors()->first())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('duplicateToOtherPath')
                    ->label(fn (AdmissionRequirementSet $record): string => 'Duplicate to '.AdmissionCycle::studentTypeLabel(self::otherPath($record->application_path)))
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->visible(fn (): bool => $this->getOwnerRecord()->state !== AdmissionCycle::StateCancelled)
                    ->action(function (AdmissionRequirementSet $record): void {
                        /** @var AdmissionCycle $cycle */
                        $cycle = $this->getOwnerRecord();
                        $targetPath = self::otherPath($record->application_path);
                        $record->loadMissing('requirements');

                        DB::transaction(function () use ($cycle, $record, $targetPath): void {
                            $rawRequirements = $record->requirements
                                ->sortBy('display_order')
                                ->values()
                                ->map(fn (AdmissionRequirement $requirement): array => [
                                    'label' => $requirement->label,
                                    'authority_reference' => $requirement->authority_reference,
                                    'purpose' => $requirement->purpose,
                                    'credential_classification' => $requirement->credential_classification,
                                    'submission_mode' => self::resolveSubmissionMode(
                                        (bool) $requirement->requires_preliminary_evidence,
                                        (string) $requirement->official_submission_method,
                                    ),
                                    'due_stage' => $requirement->due_stage,
                                    'applicant_instructions' => $requirement->applicant_instructions,
                                    'registrar_instructions' => $requirement->registrar_instructions,
                                    'exception_permitted' => (bool) $requirement->exception_permitted,
                                    'required_approving_authority' => $requirement->required_approving_authority,
                                ])
                                ->all();

                            self::createSetForPath(
                                cycle: $cycle,
                                path: $targetPath,
                                effectiveAt: $record->effective_at ?? now(config('app.timezone')),
                                rawRequirements: $rawRequirements,
                                adaptCoreForTargetPath: true,
                            );
                        });

                        Notification::make()
                            ->title('Duplicated to '.AdmissionCycle::studentTypeLabel($targetPath))
                            ->body('Review the cloned requirements (including the core credential) and publish when ready.')
                            ->success()
                            ->send();
                    }),
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->visible(fn (AdmissionRequirementSet $record): bool => $record->state === AdmissionRequirementSet::StateDraft
                        && $this->getOwnerRecord()->state !== AdmissionCycle::StateCancelled),
                DeleteAction::make()
                    ->modalWidth(Width::Large)
                    ->stickyModalHeader()
                    ->stickyModalFooter()
                    ->visible(fn (AdmissionRequirementSet $record): bool => $record->state === AdmissionRequirementSet::StateDraft
                        && $this->getOwnerRecord()->state !== AdmissionCycle::StateCancelled),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareRequirementPayload(array $data): array
    {
        $mode = (string) ($data['submission_mode'] ?? self::ModeBothOnlineAndF2f);
        [$requiresPreliminary, $officialMethod] = self::mapSubmissionMode($mode);

        $data['requires_preliminary_evidence'] = $requiresPreliminary;
        $data['official_submission_method'] = $officialMethod;
        unset($data['submission_mode']);

        if (array_key_exists('credential_classification', $data)) {
            // #110 item 14: "Can be held with approval" is the only type that permits a hold.
            $canBeHeld = $data['credential_classification'] === AdmissionRequirement::ClassificationNonCore;
            $data['exception_permitted'] = $canBeHeld;

            if (! $canBeHeld) {
                $data['required_approving_authority'] = null;
            }
        }

        if (blank($data['code'] ?? null)) {
            $data['code'] = self::generateRequirementCode((string) ($data['label'] ?? 'REQUIREMENT'));
        }

        if (! array_key_exists('authority_reference', $data)) {
            $data['authority_reference'] = null;
        }

        return $data;
    }

    public static function resolveSubmissionMode(bool $requiresPreliminary, string $officialMethod): string
    {
        return match (true) {
            $requiresPreliminary && $officialMethod === AdmissionRequirement::SubmissionInPerson => self::ModeBothOnlineAndF2f,
            $requiresPreliminary && $officialMethod === AdmissionRequirement::SubmissionSchoolToSchool => self::ModeSchoolToSchoolWithOnline,
            $requiresPreliminary => self::ModeOnlineOnly,
            $officialMethod === AdmissionRequirement::SubmissionSchoolToSchool => self::ModeSchoolToSchoolOnly,
            default => self::ModeF2fOnly,
        };
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public static function mapSubmissionMode(string $mode): array
    {
        return match ($mode) {
            self::ModeOnlineOnly => [true, AdmissionRequirement::SubmissionNone],
            self::ModeF2fOnly => [false, AdmissionRequirement::SubmissionInPerson],
            self::ModeSchoolToSchoolWithOnline => [true, AdmissionRequirement::SubmissionSchoolToSchool],
            self::ModeSchoolToSchoolOnly => [false, AdmissionRequirement::SubmissionSchoolToSchool],
            default => [true, AdmissionRequirement::SubmissionInPerson],
        };
    }

    public static function generateRequirementCode(string $label, array $usedCodes = []): string
    {
        $slug = Str::upper(Str::slug($label, '-'));
        $base = 'REQ-'.Str::limit($slug !== '' ? $slug : 'ITEM', 50, '');
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $usedCodes, true)) {
            $candidate = Str::limit($base, 58, '').'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  list<array<string, mixed>>  $rawRequirements
     */
    private static function createSetForPath(
        AdmissionCycle $cycle,
        string $path,
        mixed $effectiveAt,
        array $rawRequirements,
        bool $adaptCoreForTargetPath,
    ): AdmissionRequirementSet {
        $latestPublished = AdmissionRequirementSet::query()
            ->where('admission_cycle_id', $cycle->id)
            ->where('application_path', $path)
            ->where('state', AdmissionRequirementSet::StatePublished)
            ->latest('version')
            ->first();

        $nextVersion = ((int) AdmissionRequirementSet::query()
            ->where('admission_cycle_id', $cycle->id)
            ->where('application_path', $path)
            ->max('version')) + 1;

        $set = $cycle->requirementSets()->create([
            'application_path' => $path,
            'version' => $nextVersion,
            'effective_at' => $effectiveAt,
            'replaces_requirement_set_id' => $latestPublished?->id,
        ]);

        $usedCodes = [];

        foreach ($rawRequirements as $orderIndex => $item) {
            $payload = self::prepareRequirementPayload($item);

            if ($adaptCoreForTargetPath) {
                $payload['credential_classification'] = self::adaptClassificationForPath(
                    (string) ($payload['credential_classification'] ?? AdmissionRequirement::ClassificationNonCore),
                    $path,
                );
            }

            $payload['code'] = self::generateRequirementCode((string) ($payload['label'] ?? 'ITEM'), $usedCodes);
            $usedCodes[] = $payload['code'];
            $payload['display_order'] = $orderIndex + 1;

            $set->requirements()->create($payload);
        }

        return $set;
    }

    private static function adaptClassificationForPath(string $classification, string $targetPath): string
    {
        if ($targetPath === AdmissionCycle::PathTransferee
            && $classification === AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential) {
            return AdmissionRequirement::ClassificationCoreTransferCredential;
        }

        if ($targetPath === AdmissionCycle::PathFirstYear
            && $classification === AdmissionRequirement::ClassificationCoreTransferCredential) {
            return AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential;
        }

        return $classification;
    }

    private static function otherPath(string $path): string
    {
        return $path === AdmissionCycle::PathFirstYear
            ? AdmissionCycle::PathTransferee
            : AdmissionCycle::PathFirstYear;
    }

    /**
     * The main admission credential a list must carry for its student type (#110 item 14).
     */
    public static function mainCredentialClassificationFor(string $path): string
    {
        return $path === AdmissionCycle::PathTransferee
            ? AdmissionRequirement::ClassificationCoreTransferCredential
            : AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential;
    }

    public static function isMainCredential(?string $classification): bool
    {
        return in_array($classification, [
            AdmissionRequirement::ClassificationCoreFirstYearCompletionCredential,
            AdmissionRequirement::ClassificationCoreTransferCredential,
        ], true);
    }

    /**
     * Why a list's rows do not carry exactly one main credential for its student type, or null when they do.
     *
     * @param  list<mixed>  $classifications
     */
    public static function mainCredentialProblem(array $classifications, string $path): ?string
    {
        $expected = self::mainCredentialClassificationFor($path === self::PathBoth ? AdmissionCycle::PathFirstYear : $path);
        $mainRows = array_values(array_filter($classifications, fn (mixed $classification): bool => self::isMainCredential(is_string($classification) ? $classification : null)));
        $name = $expected === AdmissionRequirement::ClassificationCoreTransferCredential ? 'Honorable Dismissal / TOR' : 'Form 138';

        return match (true) {
            $mainRows === [] => "Add the main admission credential ({$name}) for this student type.",
            count($mainRows) > 1 => 'A requirement list can have only one main admission credential.',
            $mainRows[0] !== $expected => "This student type's main admission credential is {$name}.",
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function requirementTypeOptions(?string $current): array
    {
        if (self::isMainCredential($current)) {
            return [$current => $current === AdmissionRequirement::ClassificationCoreTransferCredential
                ? 'Main admission credential (Honorable Dismissal / TOR)'
                : 'Main admission credential (Form 138)'];
        }

        return [
            AdmissionRequirement::ClassificationCoreOtherOfficialCredential => "Required, can't be held",
            AdmissionRequirement::ClassificationNonCore => 'Can be held with approval',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function requirementTypeDescriptions(?string $current): array
    {
        if (self::isMainCredential($current)) {
            return [$current => "Every applicant of this student type must submit it before enrollment. It can't be held or removed."];
        }

        return [
            AdmissionRequirement::ClassificationCoreOtherOfficialCredential => 'The applicant must hand it in before enrollment clearance.',
            AdmissionRequirement::ClassificationNonCore => 'The Registrar may clear the applicant while it is still owed, with approval and a due date.',
        ];
    }

    /**
     * A new list's pre-filled main credential row for its student type.
     *
     * @return array<string, mixed>
     */
    private static function mainCredentialRow(string $path): array
    {
        $isTransferee = $path === AdmissionCycle::PathTransferee;

        return [
            'label' => $isTransferee ? 'Honorable Dismissal and Transcript of Records' : 'Form 138 (Senior High School Report Card)',
            'purpose' => $isTransferee
                ? 'Shows the applicant may transfer and lists the grades earned at the previous college.'
                : 'Shows the applicant completed senior high school.',
            'credential_classification' => self::mainCredentialClassificationFor($path),
            'due_stage' => AdmissionRequirement::DueEnrollmentReadiness,
            'submission_mode' => $isTransferee ? self::ModeSchoolToSchoolWithOnline : self::ModeBothOnlineAndF2f,
            'applicant_instructions' => $isTransferee
                ? 'Upload a copy, then request your previous school to send the official record to the Registrar.'
                : 'Upload a clear copy, then bring the original to the Registrar\'s office before enrollment.',
            'registrar_instructions' => $isTransferee
                ? 'Check the official record received from the previous school against the uploaded copy.'
                : 'Check the original against the uploaded copy.',
            'exception_permitted' => false,
            'required_approving_authority' => null,
        ];
    }

    /**
     * Keeps the pre-filled main credential row in step with the chosen student type.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function withMainCredentialFor(array $rows, string $path): array
    {
        $mainPath = $path === self::PathBoth ? AdmissionCycle::PathFirstYear : $path;
        $fresh = self::mainCredentialRow($mainPath);
        $otherDefaults = self::mainCredentialRow($mainPath === AdmissionCycle::PathTransferee ? AdmissionCycle::PathFirstYear : AdmissionCycle::PathTransferee);

        foreach ($rows as $key => $row) {
            if (! self::isMainCredential($row['credential_classification'] ?? null)) {
                continue;
            }

            $rows[$key]['credential_classification'] = $fresh['credential_classification'];

            foreach (['label', 'purpose', 'submission_mode', 'applicant_instructions', 'registrar_instructions'] as $field) {
                if (($row[$field] ?? null) === $otherDefaults[$field]) {
                    $rows[$key][$field] = $fresh[$field];
                }
            }
        }

        return $rows;
    }
}
