<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Applicants\AdmissionRequirementResolver;
use App\Actions\Applicants\AdmissionWindowService;
use App\Actions\Applicants\ApplicantIntakeService;
use App\Models\AdmissionRequirementPolicy;
use App\Models\ApplicantIntake;
use App\Models\ChecklistItem;
use App\Models\Program;
use App\Models\Term;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class Application extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'My Application';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.applicant.pages.application';

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    public bool $savingDraft = false;

    public ?string $manualGuardianAddress = null;

    public function mount(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant !== null, 403);

        $intake = ApplicantIntake::query()
            ->where('user_id', $applicant->id)
            ->where('status', '!=', ApplicantIntake::StatusWithdrawn)
            ->latest('id')
            ->first();

        if ($intake instanceof ApplicantIntake && $intake->status !== ApplicantIntake::StatusDraft) {
            $this->redirect(Dashboard::getUrl());

            return;
        }

        $defaults = $intake instanceof ApplicantIntake
            ? [
                ...$intake->only($this->formAttributes()),
                'term_id' => $intake->term_id,
                'document_uploads' => $intake->draft_document_references ?? [],
            ]
            : [
                'term_id' => app(AdmissionWindowService::class)->openTermIds()->first(),
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'first_name' => $applicant->first_name,
                'middle_name' => $applicant->middle_name,
                'last_name' => $applicant->last_name,
                'email' => $applicant->email,
            ];

        $guardianAddressMatchesApplicant = $this->guardianAddressMatchesApplicant($defaults);
        $defaults['guardian_address_same_as_applicant'] = $guardianAddressMatchesApplicant;
        $this->manualGuardianAddress = $guardianAddressMatchesApplicant
            ? null
            : (filled($defaults['guardian_address'] ?? null) ? (string) $defaults['guardian_address'] : null);

        $this->applicationForm()->fill($defaults);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Personal Information')
                        ->description('Application, identity, contact, and guardian details')
                        ->schema([
                            Section::make('Application Scope')
                                ->description('Your modality preference is for Registrar guidance only. Class modality remains assigned per subject offering.')
                                ->schema([
                                    Select::make('term_id')
                                        ->label('Admission Term')
                                        ->options(fn (): array => $this->admissionTermOptions())
                                        ->live()
                                        ->required(),
                                    Select::make('program_id')
                                        ->label('Preferred Program')
                                        ->options(fn (): array => Program::query()
                                            ->where('is_active', true)
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all())
                                        ->searchable()
                                        ->preload()
                                        ->required(),
                                    Select::make('admission_category')
                                        ->options([
                                            ApplicantIntake::AdmissionCategoryFirstTimeCollege => 'First-Time College Applicant',
                                            ApplicantIntake::AdmissionCategoryTransfer => 'Transfer Applicant',
                                            ApplicantIntake::AdmissionCategoryReturning => 'Returning Student / Readmission',
                                        ])
                                        ->live()
                                        ->required(),
                                    Select::make('credential_basis')
                                        ->options([
                                            ApplicantIntake::CredentialBasisSeniorHighSchool => 'Senior High School Credential',
                                            ApplicantIntake::CredentialBasisTransferCredentials => 'Transfer Credentials',
                                            ApplicantIntake::CredentialBasisPriorStudentRecord => 'Prior Student Record',
                                        ])
                                        ->live()
                                        ->required(),
                                    Select::make('modality_preference')
                                        ->label('Preferred Modality')
                                        ->options([
                                            ApplicantIntake::ModalityPreferenceFaceToFace => 'Face-to-Face',
                                            ApplicantIntake::ModalityPreferenceOnline => 'Online',
                                        ])
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->helperText('This preference does not create a separate student timetable. Each subject offering determines its delivery modality.'),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Section::make('Personal Details')
                                ->description('Fields marked * are required for final submission. You may leave them incomplete while saving a draft.')
                                ->schema([
                                    TextInput::make('first_name')->required(fn (): bool => ! $this->savingDraft)->maxLength(255),
                                    TextInput::make('middle_name')->maxLength(255),
                                    TextInput::make('last_name')->required(fn (): bool => ! $this->savingDraft)->maxLength(255),
                                    TextInput::make('extension_name')
                                        ->label('Name Extension')
                                        ->placeholder('Jr., Sr., III')
                                        ->maxLength(50),
                                    Select::make('gender')
                                        ->options([
                                            'MALE' => 'Male',
                                            'FEMALE' => 'Female',
                                            'OTHER' => 'Other',
                                            'PREFER_NOT_TO_SAY' => 'Prefer not to say',
                                        ])
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('civil_status')
                                        ->options([
                                            'SINGLE' => 'Single',
                                            'MARRIED' => 'Married',
                                            'WIDOWED' => 'Widowed',
                                            'SEPARATED' => 'Separated',
                                        ])
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    DatePicker::make('birth_date')
                                        ->label('Date of Birth')
                                        ->maxDate(now()->subDay())
                                        ->native(false)
                                        ->live()
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Placeholder::make('calculated_age')
                                        ->label('Age')
                                        ->content(fn (Get $get): string => filled($get('birth_date'))
                                            ? (string) CarbonImmutable::parse((string) $get('birth_date'))->age
                                            : 'Calculated from date of birth'),
                                    TextInput::make('birth_place')->label('Place of Birth')->required(fn (): bool => ! $this->savingDraft)->maxLength(255),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Section::make('Contact and Address')
                                ->description('Use current contact details. Fields marked * are required for final submission.')
                                ->schema([
                                    TextInput::make('email')->email()->required(fn (): bool => ! $this->savingDraft)->maxLength(255),
                                    TextInput::make('phone')
                                        ->tel()
                                        ->placeholder('09XXXXXXXXX')
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->regex('/^09\d{9}$/')
                                        ->validationMessages([
                                            'regex' => 'Enter exactly 11 digits beginning with 09.',
                                        ])
                                        ->maxLength(11),
                                    TextInput::make('address_street')
                                        ->label('Street / House Number')
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(255)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncGuardianAddress($get, $set)),
                                    TextInput::make('address_barangay')
                                        ->label('Barangay')
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(255)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncGuardianAddress($get, $set)),
                                    TextInput::make('address_city')
                                        ->label('City / Municipality')
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(255)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncGuardianAddress($get, $set)),
                                    TextInput::make('address_district')
                                        ->label('District (Optional)')
                                        ->maxLength(255)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncGuardianAddress($get, $set)),
                                    TextInput::make('address_province')
                                        ->label('Province')
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(255)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (Get $get, Set $set) => $this->syncGuardianAddress($get, $set)),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Section::make('Parent / Guardian Contact')
                                ->description('Provide the contact details of the applicant’s parent or guardian.')
                                ->schema([
                                    TextInput::make('guardian_name')->label('Parent / Guardian Full Name')->required(fn (): bool => ! $this->savingDraft)->maxLength(255),
                                    TextInput::make('guardian_phone')
                                        ->label('Parent / Guardian Contact Number')
                                        ->tel()
                                        ->placeholder('09XXXXXXXXX')
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->regex('/^09\d{9}$/')
                                        ->validationMessages([
                                            'regex' => 'Enter exactly 11 digits beginning with 09.',
                                        ])
                                        ->maxLength(11),
                                    Checkbox::make('guardian_address_same_as_applicant')
                                        ->label('Same as applicant address')
                                        ->helperText('When selected, the applicant address is copied and kept read-only. Clear this option to enter a different address.')
                                        ->live()
                                        ->dehydrated(false)
                                        ->afterStateUpdated(function (?bool $state, Get $get, Set $set): void {
                                            if ($state === true) {
                                                $currentAddress = trim((string) $get('guardian_address'));
                                                $applicantAddress = $this->applicantAddress($get);

                                                if (filled($currentAddress) && $currentAddress !== $applicantAddress) {
                                                    $this->manualGuardianAddress = $currentAddress;
                                                }

                                                $set('guardian_address', $this->applicantAddress($get));

                                                return;
                                            }

                                            $set('guardian_address', $this->manualGuardianAddress);
                                        })
                                        ->columnSpanFull(),
                                    Textarea::make('guardian_address')
                                        ->label('Parent / Guardian Address')
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->readOnly(fn (Get $get): bool => $get('guardian_address_same_as_applicant') === true)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (?string $state, Get $get): void {
                                            if ($get('guardian_address_same_as_applicant') !== true) {
                                                $this->manualGuardianAddress = filled($state) ? trim($state) : null;
                                            }
                                        })
                                        ->rows(2)
                                        ->maxLength(1000)
                                        ->columnSpanFull(),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Section::make('Applicant Education')
                                ->description('This is the school most recently attended by the applicant, not the parent or guardian.')
                                ->schema([
                                    TextInput::make('prior_school')
                                        ->label('Most Recent School Attended')
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(255)
                                        ->columnSpanFull(),
                                ])
                                ->columnSpanFull(),
                        ]),
                    Step::make('Required Documents')
                        ->description('Upload each digital requirement separately')
                        ->schema([
                            Section::make('Admission Requirements')
                                ->description('Upload digital requirements here. Requirements marked for physical submission are brought to the Registrar after the application is submitted.')
                                ->schema(fn (Get $get): array => $this->digitalRequirementFields($get))
                                ->columnSpanFull(),
                        ]),
                    Step::make('Review and Submit')
                        ->description('Confirm the application before final submission')
                        ->schema([
                            Section::make('Application Summary')
                                ->schema([
                                    Placeholder::make('scope_summary')
                                        ->label('Application')
                                        ->content(fn (Get $get): string => $this->scopeSummary($get)),
                                    Placeholder::make('identity_summary')
                                        ->label('Applicant')
                                        ->content(fn (Get $get): string => $this->identitySummary($get)),
                                    Placeholder::make('document_summary')
                                        ->label('Digital Documents')
                                        ->content(fn (Get $get): string => collect($get('document_uploads') ?? [])->filter()->count().' file(s) ready for submission'),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                            Checkbox::make('information_confirmed')
                                ->label('I confirm that the information and documents I submit are accurate.')
                                ->helperText('Required only for final submission. You may save a partial draft without confirming.'),
                        ]),
                ])
                    ->submitAction(view('filament.applicant.pages.application-submit-action'))
                    ->persistStepInQueryString('application-step')
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function saveDraft(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant !== null, 403);

        $this->savingDraft = true;

        try {
            $state = $this->applicationForm()->getState();
            app(ApplicantIntakeService::class)->saveDraft($applicant, $state);
        } catch (ValidationException $exception) {
            $this->reportValidationFailure($exception, 'Application draft was not saved', persistent: true);

            return;
        } finally {
            $this->savingDraft = false;
        }

        Notification::make()->title('Application draft saved')->success()->send();
    }

    public function submitApplication(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant !== null, 403);

        try {
            $state = $this->applicationForm()->getState();

            if (! ($state['information_confirmed'] ?? false)) {
                $this->addError(
                    'data.information_confirmed',
                    'Confirm that the application information and identity evidence are accurate before submitting.',
                );

                return;
            }

            $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $state);
            app(ApplicantIntakeService::class)->submit($draft, true);
        } catch (ValidationException $exception) {
            $this->reportValidationFailure($exception, 'Application cannot be submitted');

            return;
        }

        Notification::make()->title('Application submitted for Registrar review')->success()->send();
        $this->redirect(Dashboard::getUrl());
    }

    public function hasExistingDraft(): bool
    {
        return $this->currentDraft() instanceof ApplicantIntake;
    }

    public function currentDraft(): ?ApplicantIntake
    {
        $applicantId = Auth::id();

        if ($applicantId === null) {
            return null;
        }

        return ApplicantIntake::query()
            ->where('user_id', $applicantId)
            ->where('status', ApplicantIntake::StatusDraft)
            ->latest('id')
            ->first();
    }

    public function admissionsAreOpen(): bool
    {
        return app(AdmissionWindowService::class)->hasOpenAdmissionsWindow();
    }

    public function canSubmitApplication(): bool
    {
        $termId = (int) ($this->data['term_id'] ?? 0);

        return $termId > 0
            && app(AdmissionWindowService::class)->isAdmissionsWindowOpenForTerm($termId);
    }

    /**
     * @return array<int, string>
     */
    private function admissionTermOptions(): array
    {
        $termIds = app(AdmissionWindowService::class)->openTermIds();
        $existingTermId = ApplicantIntake::query()
            ->where('user_id', Auth::id())
            ->where('status', ApplicantIntake::StatusDraft)
            ->value('term_id');

        if ($existingTermId !== null) {
            $termIds->push((int) $existingTermId);
        }

        return Term::query()
            ->where('state', Term::StateActive)
            ->whereKey($termIds->unique()->all())
            ->orderByDesc('id')
            ->pluck('label', 'id')
            ->all();
    }

    /**
     * @return array<int, FileUpload|Placeholder>
     */
    private function digitalRequirementFields(Get $get): array
    {
        $admissionCategory = $get('admission_category');
        $credentialBasis = $get('credential_basis');

        if (! is_string($admissionCategory) || ! is_string($credentialBasis)) {
            return [
                Placeholder::make('select_application_scope')
                    ->label('Requirements unavailable')
                    ->content('Select an admission category and credential basis to load the applicable requirements.'),
            ];
        }

        $policies = app(AdmissionRequirementResolver::class)
            ->resolveFor($admissionCategory, $credentialBasis, failWhenEmpty: false)
            ->values();

        if ($policies->isEmpty()) {
            return [
                Placeholder::make('no_admission_requirements')
                    ->label('No admission requirements configured')
                    ->content('No active admission policy matches this application scope. You may save the draft, but final submission requires an effective policy configured by the Registrar.'),
            ];
        }

        return $policies
            ->map(function (AdmissionRequirementPolicy $policy): FileUpload|Placeholder {
                $label = AdmissionRequirementPolicy::requirementTypeOptions()[$policy->requirement_type]
                    ?? Str::of($policy->requirement_type)->replace('_', ' ')->title()->toString();
                $blockingLabel = AdmissionRequirementPolicy::blockingLevelOptions()[$policy->blocking_level]
                    ?? Str::of($policy->blocking_level)->replace('_', ' ')->title()->toString();
                $isBlocking = ! in_array($policy->blocking_level, [
                    ChecklistItem::BlockingRetentionOnly,
                    ChecklistItem::BlockingAdvisoryOnly,
                ], true);

                if ($policy->evidence_method === ChecklistItem::EvidenceMethodPhysicalCopy) {
                    return Placeholder::make("physical_requirement_{$policy->id}")
                        ->label($label)
                        ->content("Bring the original or certified copy to the Registrar after submitting the application. {$blockingLabel}.");
                }

                if ($policy->evidence_method === ChecklistItem::EvidenceMethodMetadataOnly) {
                    return Placeholder::make("metadata_requirement_{$policy->id}")
                        ->label($label)
                        ->content("No file upload is needed. The Registrar records or verifies this information. {$blockingLabel}.");
                }

                return FileUpload::make("document_uploads.{$policy->id}")
                    ->key("applicant-document-upload-{$policy->id}")
                    ->label($label)
                    ->disk('local')
                    ->directory('applicant-requirement-documents/'.Auth::id()."/{$policy->id}")
                    ->visibility('private')
                    ->preventFilePathTampering(
                        allowFilePathUsing: fn (string $file): bool => $this->isPermittedDocumentPath($file, $policy),
                    )
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxFiles(1)
                    ->maxSize(5120)
                    ->openable()
                    ->required(fn (): bool => $isBlocking && ! $this->savingDraft)
                    ->helperText(function (Get $get) use ($isBlocking, $policy): string {
                        $requirementGuidance = $isBlocking
                            ? 'Required before final submission. PDF, JPG, or PNG; maximum 5 MB.'
                            : 'Optional at intake. PDF, JPG, or PNG; maximum 5 MB.';

                        if (filled($get("document_uploads.{$policy->id}"))) {
                            return "Saved in this draft. Select the arrow beside the filename to open it, or Remove to replace it. {$requirementGuidance}";
                        }

                        return $requirementGuidance;
                    });
            })
            ->all();
    }

    private function isPermittedDocumentPath(string $path, AdmissionRequirementPolicy $policy): bool
    {
        $applicantId = (int) Auth::id();

        if ($this->pathIsInside(
            $path,
            "applicant-requirement-documents/{$applicantId}/{$policy->id}",
        )) {
            return true;
        }

        return $policy->requirement_type === 'IDENTITY_DOCUMENT'
            && $this->pathIsInside($path, "applicant-identity-documents/{$applicantId}");
    }

    private function pathIsInside(string $path, string $directory): bool
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedDirectory = trim(str_replace('\\', '/', $directory), '/').'/';

        return ! str_contains($normalizedPath, '../')
            && str_starts_with($normalizedPath, $normalizedDirectory)
            && strlen($normalizedPath) > strlen($normalizedDirectory);
    }

    private function scopeSummary(Get $get): string
    {
        $term = Term::query()->whereKey($get('term_id'))->value('label') ?? 'No admission term selected';
        $program = Program::query()->whereKey($get('program_id'))->value('name') ?? 'No program selected';
        $modality = match ($get('modality_preference')) {
            ApplicantIntake::ModalityPreferenceFaceToFace => 'Face-to-Face preference',
            ApplicantIntake::ModalityPreferenceOnline => 'Online preference',
            default => 'No modality preference selected',
        };

        return "{$term}; {$program}; {$modality}";
    }

    private function identitySummary(Get $get): string
    {
        $name = collect([
            $get('first_name'),
            $get('middle_name'),
            $get('last_name'),
            $get('extension_name'),
        ])->filter()->implode(' ');

        return filled($name)
            ? $name.'; '.((string) ($get('email') ?: 'no email provided'))
            : 'Applicant name is incomplete.';
    }

    private function syncGuardianAddress(Get $get, Set $set): void
    {
        if ($get('guardian_address_same_as_applicant') === true) {
            $set('guardian_address', $this->applicantAddress($get));
        }
    }

    private function reportValidationFailure(
        ValidationException $exception,
        string $title,
        bool $persistent = false,
    ): void {
        foreach ($exception->errors() as $field => $messages) {
            $errorKey = Str::startsWith($field, 'data.') ? $field : "data.{$field}";
            $this->addError($errorKey, (string) collect($messages)->first());
        }

        $notification = Notification::make()
            ->title($title)
            ->body(
                (string) (collect($exception->errors())->flatten()->first()
                    ?? 'Review the highlighted information and try again.'),
            )
            ->danger();

        if ($persistent) {
            $notification->persistent();
        }

        $notification->send();
    }

    private function applicantAddress(Get $get): string
    {
        return collect([
            $get('address_street'),
            $get('address_barangay'),
            $get('address_city'),
            $get('address_district'),
            $get('address_province'),
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && filled(trim($value)))
            ->map(fn (string $value): string => trim($value))
            ->implode(', ');
    }

    /** @param array<string, mixed> $state */
    private function guardianAddressMatchesApplicant(array $state): bool
    {
        $applicantAddress = collect([
            $state['address_street'] ?? null,
            $state['address_barangay'] ?? null,
            $state['address_city'] ?? null,
            $state['address_district'] ?? null,
            $state['address_province'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && filled(trim($value)))
            ->map(fn (string $value): string => trim($value))
            ->implode(', ');

        return filled($applicantAddress)
            && $applicantAddress === trim((string) ($state['guardian_address'] ?? ''));
    }

    /** @return list<string> */
    private function formAttributes(): array
    {
        return [
            'term_id', 'program_id', 'admission_category', 'credential_basis',
            'modality_preference', 'first_name', 'middle_name', 'last_name',
            'extension_name', 'birth_date', 'gender', 'civil_status', 'birth_place',
            'email', 'phone', 'address_barangay', 'address_street', 'address_city',
            'address_district', 'address_province', 'prior_school', 'guardian_name',
            'guardian_phone', 'guardian_address',
        ];
    }

    private function applicationForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Applicant application form schema is unavailable.');
    }
}
