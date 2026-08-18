<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicantIntake;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\Program;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** @property Schema $form */
class Application extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Application';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.applicant.pages.application';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public bool $savingDraft = false;

    private bool $applicationResolved = false;

    private ?AdmissionApplication $resolvedApplication = null;

    public function mount(): void
    {
        $application = $this->currentApplication();
        $this->form->fill($application instanceof AdmissionApplication
            ? $this->applicationState($application)
            : $this->initialState());
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('applicant');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('discardDraft')
                ->label('Discard draft')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Only this unsubmitted draft and its temporary uploads will be removed. Submitted history is never deleted.')
                ->visible(function (): bool {
                    $application = $this->currentApplication();

                    return $application instanceof AdmissionApplication
                        && $application->application_state === AdmissionApplication::StateDraft
                        && $application->current_submission_version_id === null;
                })
                ->action(function (): void {
                    $application = $this->currentApplication();
                    $applicant = Auth::user();
                    abort_unless($application instanceof AdmissionApplication && $applicant instanceof User, 404);

                    app(DiscardAdmissionApplication::class)->execute($application, $applicant);
                    Notification::make()->title('Draft discarded')->success()->send();
                    $this->redirect(Dashboard::getUrl());
                }),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Application choice')
                        ->icon(Heroicon::OutlinedAcademicCap)
                        ->schema([
                            Section::make('Choose the current admission scope')
                                ->schema([
                                    Select::make('admission_cycle_id')
                                        ->label('Admission Cycle')
                                        ->options(fn (): array => $this->cycleOptions())
                                        ->live()
                                        ->searchable()
                                        ->disabled(fn (): bool => $this->isCorrectionMode())
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('application_path')
                                        ->label('Application path')
                                        ->options([
                                            AdmissionApplication::PathFirstYear => 'First year',
                                            AdmissionApplication::PathTransferee => 'Transferee',
                                        ])
                                        ->live()
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('application_path'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('program_id')
                                        ->label('Program')
                                        ->options(fn (Get $get): array => $this->programOptions(
                                            (int) $get('admission_cycle_id'),
                                            (string) $get('application_path'),
                                        ))
                                        ->searchable()
                                        ->preload()
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('program_id'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Identity and contact')
                        ->icon(Heroicon::OutlinedIdentification)
                        ->schema([
                            Section::make('Minimum legal identity')
                                ->schema([
                                    TextInput::make('first_name')->label('Legal first name')->disabled(fn (): bool => ! $this->fieldIsEditable('first_name'))->required(fn (): bool => ! $this->savingDraft)->maxLength(100),
                                    TextInput::make('middle_name')->label('Middle name (optional)')->disabled(fn (): bool => ! $this->fieldIsEditable('middle_name'))->maxLength(100),
                                    TextInput::make('last_name')->label('Legal last name')->disabled(fn (): bool => ! $this->fieldIsEditable('last_name'))->required(fn (): bool => ! $this->savingDraft)->maxLength(100),
                                    TextInput::make('extension_name')->label('Extension (optional)')->disabled(fn (): bool => ! $this->fieldIsEditable('extension_name'))->maxLength(30),
                                    DatePicker::make('birth_date')
                                        ->label('Birth date')
                                        ->native(false)
                                        ->maxDate(now()->subDay())
                                        ->live()
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('birth_date'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('citizenship_country_code')
                                        ->label('Citizenship')
                                        ->options(['PH' => 'Philippines'])
                                        ->helperText('Foreign-student processing is outside this application path.')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('citizenship_country_code'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    TextInput::make('email')
                                        ->label('Verified account email')
                                        ->email()
                                        ->disabled()
                                        ->dehydrated(),
                                    TextInput::make('phone')
                                        ->label('Mobile number')
                                        ->tel()
                                        ->regex('/^09\d{9}$/')
                                        ->validationMessages(['regex' => 'Enter an 11-digit Philippine mobile number beginning with 09.'])
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('phone'))
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(11),
                                    TextInput::make('current_city_municipality')
                                        ->label('Current city or municipality')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('current_city_municipality'))
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(120),
                                    TextInput::make('current_province')
                                        ->label('Current province')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('current_province'))
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(120),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                            Section::make('Guardian contact')
                                ->description('Required only when the Applicant is under 18 on submission.')
                                ->schema([
                                    TextInput::make('guardian_full_name')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('guardian_full_name'))
                                        ->required(fn (Get $get): bool => ! $this->savingDraft && $this->isMinor($get('birth_date')))
                                        ->maxLength(160),
                                    TextInput::make('guardian_relationship')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('guardian_relationship'))
                                        ->required(fn (Get $get): bool => ! $this->savingDraft && $this->isMinor($get('birth_date')))
                                        ->minLength(1)
                                        ->maxLength(60),
                                    TextInput::make('guardian_mobile')
                                        ->tel()
                                        ->regex('/^09\d{9}$/')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('guardian_mobile'))
                                        ->required(fn (Get $get): bool => ! $this->savingDraft && $this->isMinor($get('birth_date')))
                                        ->maxLength(11),
                                ])
                                ->visible(fn (Get $get): bool => $this->isMinor($get('birth_date')))
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Prior education')
                        ->icon(Heroicon::OutlinedBuildingLibrary)
                        ->schema([
                            Section::make('Prior-school credential')
                                ->schema([
                                    TextInput::make('prior_school_name')
                                        ->label('Official prior-school name')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_name'))
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(160),
                                    Select::make('prior_school_country_code')
                                        ->label('Prior-school country')
                                        ->options(['PH' => 'Philippines'])
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_country_code'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('credential_basis')
                                        ->label('Credential basis')
                                        ->options(fn (Get $get): array => $this->credentialBasisOptions((string) $get('application_path')))
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('credential_basis'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    TextInput::make('prior_school_completion_year')
                                        ->label('Completion or graduation year')
                                        ->numeric()
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_completion_year'))
                                        ->minValue(1900)
                                        ->maxValue((int) now('Asia/Manila')->format('Y'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    TextInput::make('lrn')
                                        ->label('LRN (when applicable)')
                                        ->regex('/^\d{12}$/')
                                        ->validationMessages(['regex' => 'Enter exactly 12 digits.'])
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('lrn'))
                                        ->maxLength(12),
                                    TextInput::make('prior_college_identifier')
                                        ->label('Prior-college identifier (when available)')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_college_identifier'))
                                        ->maxLength(64)
                                        ->visible(fn (Get $get): bool => $get('application_path') === AdmissionApplication::PathTransferee),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Preliminary evidence')
                        ->icon(Heroicon::OutlinedPaperClip)
                        ->schema(fn (Get $get): array => [
                            Section::make('Private preliminary evidence')
                                ->description('Each file is private, versioned, checksum-tracked, and distinct from official credential verification.')
                                ->schema($this->evidenceFields(
                                    (int) $get('admission_cycle_id'),
                                    (string) $get('application_path'),
                                ))
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Review and submit')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->schema([
                            Section::make('Review the exact submission')
                                ->schema([
                                    Placeholder::make('review_summary')
                                        ->label('Before submitting')
                                        ->content('Review every step. Submission creates a stable Application reference and immutable snapshot. Later changes require a scoped correction.'),
                                    Checkbox::make('privacy_acknowledged')
                                        ->label('I acknowledge the current TALA Privacy Notice for this Admission Cycle.')
                                        ->accepted(fn (): bool => ! $this->savingDraft)
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Checkbox::make('accuracy_declared')
                                        ->label('I declare that the submitted information and preliminary evidence are accurate to the best of my knowledge.')
                                        ->accepted(fn (): bool => ! $this->savingDraft)
                                        ->required(fn (): bool => ! $this->savingDraft),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                ])
                    ->submitAction(view('filament.applicant.components.application-submit-action'))
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function saveDraft(): void
    {
        $this->savingDraft = true;

        try {
            $state = $this->form->getState();
            $application = $this->saveApplication($state);
            $this->persistEvidence($application, (array) ($state['evidence'] ?? []));
            Notification::make()->title('Draft saved')->success()->send();
            $this->redirect(self::getUrl());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Draft could not be saved')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        } finally {
            $this->savingDraft = false;
        }
    }

    public function submitApplication(): void
    {
        $this->savingDraft = false;

        try {
            $state = $this->form->getState();
            $application = $this->saveApplication($state);
            $this->persistEvidence($application, (array) ($state['evidence'] ?? []));
            $applicant = Auth::user();
            abort_unless($applicant instanceof User, 403);
            app(SubmitAdmissionApplication::class)->execute($application, $applicant);
            Notification::make()
                ->title('Application submitted')
                ->body('Your stable Application reference and version-bound acknowledgment are now available.')
                ->success()
                ->send();
            $this->redirect(Dashboard::getUrl());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Application could not be submitted')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        }
    }

    public function currentApplication(): ?AdmissionApplication
    {
        if ($this->applicationResolved) {
            return $this->resolvedApplication;
        }

        $applicant = Auth::user();

        if (! $applicant instanceof User) {
            return null;
        }

        $this->applicationResolved = true;
        $this->resolvedApplication = AdmissionApplication::query()
            ->canonical()
            ->with(['correctionRequests.items', 'evidenceVersions'])
            ->whereBelongsTo($applicant, 'user')
            ->whereIn('application_state', [
                AdmissionApplication::StateDraft,
                AdmissionApplication::StateActionNeeded,
            ])
            ->latest('id')
            ->first();

        return $this->resolvedApplication;
    }

    public function admissionsAreOpen(): bool
    {
        return AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->exists();
    }

    public function hasExistingDraft(): bool
    {
        return $this->currentApplication() instanceof AdmissionApplication;
    }

    private function saveApplication(array $state): AdmissionApplication
    {
        $applicant = Auth::user();
        abort_unless($applicant instanceof User, 403);
        $cycle = AdmissionCycle::query()->findOrFail((int) $state['admission_cycle_id']);
        $payload = collect($state)
            ->except(['evidence', 'email'])
            ->all();

        return app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            $payload,
            $this->currentApplication(),
        );
    }

    /** @param array<int|string, mixed> $evidence */
    private function persistEvidence(AdmissionApplication $application, array $evidence): void
    {
        $applicant = Auth::user();
        abort_unless($applicant instanceof User, 403);

        foreach ($evidence as $requirementId => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $requirement = AdmissionRequirement::query()->findOrFail((int) $requirementId);
            $existing = $application->evidenceVersions()
                ->where('admission_requirement_id', $requirement->id)
                ->latest('uploaded_at')
                ->first();

            if ($existing) {
                app(AdmissionEvidenceService::class)->replace($existing, $applicant, $file);
            } else {
                app(AdmissionEvidenceService::class)->store($application, $requirement, $applicant, $file);
            }
        }
    }

    /** @return list<FileUpload|Placeholder> */
    private function evidenceFields(int $cycleId, string $path): array
    {
        if ($cycleId < 1 || ! in_array($path, [AdmissionApplication::PathFirstYear, AdmissionApplication::PathTransferee], true)) {
            return [
                Placeholder::make('choose_scope_first')
                    ->content('Choose an Admission Cycle and path before adding evidence.'),
            ];
        }

        $set = AdmissionRequirementSet::query()
            ->where('admission_cycle_id', $cycleId)
            ->where('application_path', $path)
            ->where('state', AdmissionRequirementSet::StatePublished)
            ->with('requirements')
            ->latest('version')
            ->first();

        if (! $set instanceof AdmissionRequirementSet) {
            return [
                Placeholder::make('requirements_unavailable')
                    ->content('The published Requirement Set is unavailable. Save no submission and ask the Registrar to correct the cycle.'),
            ];
        }

        $requirements = $set->requirements
            ->where('requires_preliminary_evidence', true)
            ->sortBy('display_order');

        if ($this->isCorrectionMode()) {
            $requirements = $requirements->whereIn('id', $this->editableEvidenceRequirementIds());
        }

        $fields = $requirements
            ->map(fn (AdmissionRequirement $requirement): FileUpload => FileUpload::make("evidence.{$requirement->id}")
                ->label($requirement->label)
                ->helperText("{$requirement->purpose} PDF, JPEG, or PNG; maximum 10 MiB.")
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(10240)
                ->maxFiles(1)
                ->storeFiles(false)
                ->required(fn (): bool => ! $this->savingDraft && ! $this->hasEvidence($requirement)))
            ->values()
            ->all();

        return $fields !== [] ? $fields : [
            Placeholder::make('no_preliminary_uploads')
                ->content('This Requirement Set has no preliminary digital upload. Official credential instructions remain visible after submission.'),
        ];
    }

    private function hasEvidence(AdmissionRequirement $requirement): bool
    {
        $application = $this->currentApplication();

        return $application instanceof AdmissionApplication
            && $application->evidenceVersions()
                ->where('admission_requirement_id', $requirement->id)
                ->exists();
    }

    public function isCorrectionMode(): bool
    {
        return $this->currentApplication()?->application_state === AdmissionApplication::StateActionNeeded;
    }

    public function activeCorrectionRequest(): ?ApplicationCorrectionRequest
    {
        return $this->currentApplication()?->correctionRequests
            ->where('state', ApplicationCorrectionRequest::StateActive)
            ->sortByDesc('requested_at')
            ->first();
    }

    private function fieldIsEditable(string $field): bool
    {
        if (! $this->isCorrectionMode()) {
            return true;
        }

        $request = $this->activeCorrectionRequest();

        return $request?->items
            ->where('scope_type', ApplicationCorrectionItem::ScopeField)
            ->contains('scope_key', $field) ?? false;
    }

    /** @return list<int> */
    private function editableEvidenceRequirementIds(): array
    {
        $request = $this->activeCorrectionRequest();

        return $request?->items
            ->where('scope_type', ApplicationCorrectionItem::ScopeEvidence)
            ->pluck('admission_requirement_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all() ?? [];
    }

    /** @return array<int, string> */
    private function cycleOptions(): array
    {
        $availableCycleIds = AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->whereDoesntHave('applications', fn (Builder $applicationQuery): Builder => $applicationQuery
                ->whereNotNull('admission_cycle_id')
                ->whereNotNull('application_state')
                ->where('user_id', Auth::id()))
            ->pluck('id');

        $currentCycleId = $this->currentApplication()?->admission_cycle_id;
        if ($currentCycleId !== null) {
            $availableCycleIds->push($currentCycleId);
        }

        return AdmissionCycle::query()
            ->whereIn('id', $availableCycleIds->unique())
            ->orderBy('closes_at')
            ->pluck('label', 'id')
            ->all();
    }

    /** @return array<int, string> */
    private function programOptions(int $cycleId, string $path): array
    {
        if ($cycleId < 1) {
            return [];
        }

        $pivotColumn = $path === AdmissionApplication::PathTransferee
            ? 'accepts_transferee'
            : 'accepts_first_year';

        return Program::query()
            ->whereHas('admissionCycles', fn ($query) => $query
                ->where('admission_cycles.id', $cycleId)
                ->where("admission_cycle_program.{$pivotColumn}", true))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<string, string> */
    private function credentialBasisOptions(string $path): array
    {
        if ($path === AdmissionApplication::PathTransferee) {
            return [ApplicantIntake::CredentialBasisTransferCredentials => 'Transfer Credential'];
        }

        return [
            ApplicantIntake::CredentialBasisSeniorHighSchool => 'Senior High School credential',
            'ALS_AE' => 'ALS Accreditation and Equivalency',
            'PEPT' => 'Philippine Educational Placement Test',
        ];
    }

    private function isMinor(mixed $birthDate): bool
    {
        return filled($birthDate) && CarbonImmutable::parse((string) $birthDate)->age < 18;
    }

    /** @return array<string, mixed> */
    private function initialState(): array
    {
        $cycle = AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->whereDoesntHave('applications', fn (Builder $applicationQuery): Builder => $applicationQuery
                ->whereNotNull('admission_cycle_id')
                ->whereNotNull('application_state')
                ->where('user_id', Auth::id()))
            ->orderBy('closes_at')
            ->first();

        return [
            'admission_cycle_id' => $cycle?->id,
            'application_path' => AdmissionApplication::PathFirstYear,
            'citizenship_country_code' => 'PH',
            'prior_school_country_code' => 'PH',
            'email' => Auth::user()?->email,
        ];
    }

    /** @return array<string, mixed> */
    private function applicationState(AdmissionApplication $application): array
    {
        return collect($application->only([
            'admission_cycle_id',
            'application_path',
            'program_id',
            'first_name',
            'middle_name',
            'last_name',
            'extension_name',
            'birth_date',
            'citizenship_country_code',
            'email',
            'phone',
            'current_city_municipality',
            'current_province',
            'guardian_full_name',
            'guardian_relationship',
            'guardian_mobile',
            'prior_school_name',
            'prior_school_country_code',
            'credential_basis',
            'prior_school_completion_year',
            'lrn',
            'prior_college_identifier',
        ]))->merge([
            'privacy_acknowledged' => $application->privacy_acknowledged_at !== null,
            'accuracy_declared' => false,
        ])->all();
    }
}
