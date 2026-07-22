<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Applicants\AdmissionRequirementResolver;
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

    public function mount(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant !== null, 403);

        $intake = ApplicantIntake::query()->where('user_id', $applicant->id)->first();

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
                'term_id' => Term::query()->where('state', Term::StateActive)->value('id'),
                'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
                'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
                'first_name' => $applicant->first_name,
                'middle_name' => $applicant->middle_name,
                'last_name' => $applicant->last_name,
                'email' => $applicant->email,
            ];

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
                                        ->options(fn (): array => Term::query()
                                            ->where('state', Term::StateActive)
                                            ->orderByDesc('id')
                                            ->pluck('label', 'id')
                                            ->all())
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
                                        ->helperText('This preference does not create a separate student timetable. Each subject offering determines its delivery modality.'),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Section::make('Personal Details')
                                ->schema([
                                    TextInput::make('first_name')->maxLength(255),
                                    TextInput::make('middle_name')->maxLength(255),
                                    TextInput::make('last_name')->maxLength(255),
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
                                        ]),
                                    Select::make('civil_status')
                                        ->options([
                                            'SINGLE' => 'Single',
                                            'MARRIED' => 'Married',
                                            'WIDOWED' => 'Widowed',
                                            'SEPARATED' => 'Separated',
                                        ]),
                                    DatePicker::make('birth_date')
                                        ->label('Date of Birth')
                                        ->maxDate(now()->subDay())
                                        ->native(false)
                                        ->live(),
                                    Placeholder::make('calculated_age')
                                        ->label('Age')
                                        ->content(fn (Get $get): string => filled($get('birth_date'))
                                            ? (string) CarbonImmutable::parse((string) $get('birth_date'))->age
                                            : 'Calculated from date of birth'),
                                    TextInput::make('birth_place')->label('Place of Birth')->maxLength(255),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Section::make('Contact and Address')
                                ->schema([
                                    TextInput::make('email')->email()->maxLength(255),
                                    TextInput::make('phone')->tel()->placeholder('09XXXXXXXXX')->maxLength(11),
                                    TextInput::make('address_street')->label('Street / House Number')->maxLength(255),
                                    TextInput::make('address_barangay')->label('Barangay')->maxLength(255),
                                    TextInput::make('address_city')->label('City / Municipality')->maxLength(255),
                                    TextInput::make('address_district')->label('District (Optional)')->maxLength(255),
                                    TextInput::make('address_province')->label('Province')->maxLength(255),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Section::make('Guardian and Prior School')
                                ->schema([
                                    TextInput::make('guardian_name')->label('Parent / Guardian Full Name')->maxLength(255),
                                    TextInput::make('guardian_phone')->label('Parent / Guardian Contact Number')->tel()->placeholder('09XXXXXXXXX')->maxLength(11),
                                    Textarea::make('guardian_address')->label('Parent / Guardian Address')->rows(2)->maxLength(1000)->columnSpanFull(),
                                    TextInput::make('prior_school')->label('Prior School')->maxLength(255)->columnSpanFull(),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Required Documents')
                        ->description('Upload each digital requirement separately')
                        ->schema([
                            Section::make('Digital Requirements')
                                ->description('Files are private and reviewed individually by the Registrar. Physical and metadata-only requirements are tracked after submission.')
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

        app(ApplicantIntakeService::class)->saveDraft($applicant, $this->applicationForm()->getState());

        Notification::make()->title('Application draft saved')->success()->send();
    }

    public function submitApplication(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant !== null, 403);

        $state = $this->applicationForm()->getState();

        if (! ($state['information_confirmed'] ?? false)) {
            $this->addError(
                'data.information_confirmed',
                'Confirm that the application information and identity evidence are accurate before submitting.',
            );

            return;
        }

        try {
            $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $state);
            app(ApplicantIntakeService::class)->submit($draft, true);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError("data.{$field}", (string) collect($messages)->first());
            }

            $message = collect($exception->errors())->flatten()->first()
                ?? 'Review the application details and try again.';

            Notification::make()
                ->title('Application cannot be submitted')
                ->body($message)
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title('Application submitted for Registrar review')->success()->send();
        $this->redirect(Dashboard::getUrl());
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
            ->where('evidence_method', ChecklistItem::EvidenceMethodDigitalUpload)
            ->values();

        if ($policies->isEmpty()) {
            return [
                Placeholder::make('no_digital_requirements')
                    ->label('No digital uploads configured')
                    ->content('No active digital-upload policy matches this application scope. You may save the draft, but final submission requires an effective admission policy.'),
            ];
        }

        return $policies
            ->map(function (AdmissionRequirementPolicy $policy): FileUpload {
                $label = AdmissionRequirementPolicy::requirementTypeOptions()[$policy->requirement_type]
                    ?? Str::of($policy->requirement_type)->replace('_', ' ')->title()->toString();
                $isBlocking = ! in_array($policy->blocking_level, [
                    ChecklistItem::BlockingRetentionOnly,
                    ChecklistItem::BlockingAdvisoryOnly,
                ], true);

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
                    ->helperText($isBlocking
                        ? 'Required before final submission. PDF, JPG, or PNG; maximum 5 MB.'
                        : 'Optional at intake. PDF, JPG, or PNG; maximum 5 MB.');
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
