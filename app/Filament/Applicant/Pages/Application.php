<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\ResolveAdmissionEntryCycle;
use App\Actions\Admissions\ResolveAdmissionRequirementSet;
use App\Actions\Admissions\SaveAdmissionApplication;
use App\Actions\Admissions\SubmitAdmissionApplication;
use App\Filament\Components\ResumableWizard;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\DocumentEvidence;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

/** @property Schema $form */
class Application extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Application';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.applicant.pages.application';

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    #[Url(as: 'application')]
    public ?int $sourceApplicationId = null;

    #[Url(as: 'program')]
    public ?int $entryProgramId = null;

    #[Locked]
    public ?int $mountedSubmissionVersionId = null;

    public bool $savingDraft = false;

    public string $saveStatus = 'saved';

    public string $saveStatusMessage = 'Saved progress restored.';

    private bool $applicationResolved = false;

    private ?AdmissionApplication $resolvedApplication = null;

    private bool $submissionCommitted = false;

    private ?bool $submittedEditAllowed = null;

    private ?string $submissionOperationId = null;

    public function mount(): void
    {
        if ($this->sourceApplicationId !== null) {
            $source = AdmissionApplication::query()->canonical()
                ->whereBelongsTo($this->applicationOwner(), 'user')
                ->findOrFail($this->sourceApplicationId);
            if (! in_array($source->application_state, [AdmissionApplication::StateDraft, AdmissionApplication::StateActionNeeded], true) && ! $source->allowsSubmittedEdit()) {
                $this->redirect($this->nonDraftRedirectUrl($source));

                return;
            }
        }

        $this->entryProgramId ??= session('public_entry.program_id');

        $application = $this->currentApplication();
        $this->mountedSubmissionVersionId = $application?->current_submission_version_id;
        $this->saveStatus = $application instanceof AdmissionApplication ? 'saved' : 'not-saved';
        $this->saveStatusMessage = $application instanceof AdmissionApplication
            ? 'Saved progress restored.'
            : 'Draft has not been saved yet.';
        if ($this->isStudentTypeCorrection()) {
            $this->saveStatusMessage = 'Requested correction loaded. Changes stay on this page until you resubmit.';
        }
        $this->form->fill($application instanceof AdmissionApplication
            ? $this->applicationState($application)
            : $this->initialState());
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasRole('applicant');
    }

    public static function getNavigationUrl(): string
    {
        $query = AdmissionApplication::query()->canonical()->where('user_id', Auth::id());
        $sourceId = request()->integer('application');
        $application = $sourceId > 0
            ? $query->find($sourceId)
            : $query->latest('updated_at')->latest('id')->first();

        if (! $application instanceof AdmissionApplication) {
            return static::getUrl(panel: 'applicant');
        }

        return in_array($application->application_state, [
            AdmissionApplication::StateDraft,
            AdmissionApplication::StateActionNeeded,
        ], true) || $application->allowsSubmittedEdit()
            ? static::getUrl(['application' => $application->id], panel: 'applicant')
            : Requirements::getUrl(['application' => $application->id], panel: 'applicant');
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('saveAndExit')
                    ->label('Save and exit')
                    ->icon(Heroicon::OutlinedArrowRightOnRectangle)
                    ->color('gray')
                    ->visible(fn (): bool => ! $this->requiresCompleteSubmission())
                    ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                    ->action(function (): void {
                        $this->saveAndExit();
                    }),
            ])->label('Application options')->icon(Heroicon::OutlinedChevronDown)->button()->color('gray')
                ->extraAttributes(['data-application-options' => true]),
            Action::make('discardDraft')
                ->modalSubmitActionLabel('Discard draft')
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
                    $applicant = $this->applicationOwner();
                    $actor = $this->actingUser();
                    abort_unless($application instanceof AdmissionApplication
                        && $applicant instanceof User
                        && $actor instanceof User, 404);

                    app(DiscardAdmissionApplication::class)->execute($application, $applicant, $actor);
                    $this->dispatch('draft-saved');
                    Notification::make()->title('Draft discarded')->success()->send();
                    $this->redirect($this->afterDiscardUrl(), navigate: true);
                }),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(['application' => $this->sourceApplicationId ?? $this->currentApplication()?->id]) => 'Home', 'Application'];
    }

    public function form(Schema $schema): Schema
    {
        $schema
            ->components([
                ...$this->assistanceComponents(),
                Hidden::make('requirement_set_id'),
                ResumableWizard::make([
                    Step::make('Program and type')
                        ->afterValidation(fn () => $this->saveBeforeContinuing())
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description(fn (): string => $this->stepDescription(1))
                        ->icon(Heroicon::OutlinedAcademicCap)
                        ->schema([
                            Section::make(fn (): string => $this->isStudentTypeCorrection() ? 'Confirm the requested Student type' : "Let's get your application started.")
                                ->schema([
                                    Hidden::make('admission_cycle_id'),
                                    Radio::make('application_path')
                                        ->label('Student type')
                                        ->options(AdmissionCycle::studentTypeOptions())
                                        ->descriptions([
                                            AdmissionApplication::PathFirstYear => 'Applying to college for the first time.',
                                            AdmissionApplication::PathTransferee => 'Previously attended another college and applying to transfer here.',
                                        ])
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            if ($this->fieldIsEditable('credential_basis')) {
                                                $set('credential_basis', $get('application_path') === AdmissionApplication::PathTransferee
                                                    ? AdmissionApplication::CredentialTransfer : null);
                                            }
                                            $this->resolveEntryContext($set, $get);
                                            $this->refreshRequirementState(
                                                $set,
                                                (int) $get('admission_cycle_id'),
                                                (string) $get('application_path'),
                                            );
                                        })
                                        ->disabled(fn (): bool => $this->isStudentTypeCorrection() || ! $this->fieldIsEditable('application_path'))
                                        ->dehydrated(fn (): bool => $this->isStudentTypeCorrection() || $this->fieldIsEditable('application_path'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('program_id')
                                        ->label('Program')
                                        ->options(fn (Get $get): array => $this->programOptions(
                                            (int) $get('admission_cycle_id'),
                                            (string) $get('application_path'),
                                        ))
                                        ->searchable()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set, Get $get) => $this->resolveEntryContext($set, $get))
                                        ->preload()
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('program_id'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Identity and contact')
                        ->afterValidation(fn () => $this->saveBeforeContinuing())
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description(fn (): string => $this->stepDescription(2))
                        ->icon(Heroicon::OutlinedIdentification)
                        ->schema([
                            Section::make('Personal information')
                                ->schema([
                                    TextInput::make('first_name')->label('First name')->disabled(fn (): bool => ! $this->fieldIsEditable('first_name'))->required(fn (): bool => ! $this->savingDraft)->maxLength(100),
                                    TextInput::make('last_name')->label('Last name')->disabled(fn (): bool => ! $this->fieldIsEditable('last_name'))->required(fn (): bool => ! $this->savingDraft)->maxLength(100),
                                    TextInput::make('middle_name')->label('Middle name (optional)')->disabled(fn (): bool => ! $this->fieldIsEditable('middle_name'))->maxLength(100),
                                    TextInput::make('extension_name')->label('Suffix (optional)')->disabled(fn (): bool => ! $this->fieldIsEditable('extension_name'))->maxLength(30),
                                    DatePicker::make('birth_date')->label('Birth date')
                                        ->native()->maxDate(now('Asia/Manila'))->live()
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('birth_date'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('citizenship_country_code')->label('Citizenship')
                                        ->options(['PH' => 'Philippines', 'ZZ' => 'Foreign or another citizenship'])
                                        ->helperText(fn (): HtmlString => $this->contactSupportLink('Other citizenship?'))
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('citizenship_country_code'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                ])
                                ->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
                            Section::make('Optional identity details')
                                ->description('The Registrar uses these optional details to compare your identity records. You can continue your application without providing them.')
                                ->schema([
                                    Checkbox::make('optional_identity_consent')
                                        ->label('I agree to the use of my optional sex and civil-status details for identity-record comparison')
                                        ->live()
                                        ->afterStateUpdatedJs(<<<'JS'
                                            if (! $state) {
                                                $set('gender', null);
                                                $set('civil_status', null);
                                            }
                                            JS)
                                        ->afterStateUpdated(function (Set $set, ?bool $state): void {
                                            if ($state !== true) {
                                                $set('gender', null);
                                                $set('civil_status', null);
                                                $application = $this->currentApplication();
                                                if ($application?->application_state === AdmissionApplication::StateDraft) {
                                                    $this->saveApplication(['admission_cycle_id' => $application->admission_cycle_id, 'optional_identity_consent' => false]);
                                                    $this->saveStatusMessage = 'Optional identity details were cleared from this Draft.';
                                                }
                                            }
                                        })->columnSpanFull(),
                                    TextInput::make('gender')->label('Sex (optional)')->maxLength(40)
                                        ->visible(fn (Get $get): bool => $get('optional_identity_consent') === true)
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('gender')),
                                    TextInput::make('civil_status')->label('Civil status (optional)')->maxLength(40)
                                        ->visible(fn (Get $get): bool => $get('optional_identity_consent') === true)
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('civil_status')),
                                ])
                                ->visible(fn (): bool => $this->actingUser()?->id === $this->applicationOwner()?->id
                                    && ($this->fieldIsEditable('gender') || $this->fieldIsEditable('civil_status')))
                                ->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
                            Section::make('Contact and address')
                                ->schema([
                                    TextInput::make('email')->label('Email address (verified)')->email()->disabled()->dehydrated(),
                                    TextInput::make('phone')->label('Mobile number')->tel()->regex('/^09\\d{9}$/')
                                        ->validationMessages(['regex' => 'Enter an 11-digit Philippine mobile number beginning with 09.'])
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('phone'))->required(fn (): bool => ! $this->savingDraft)->maxLength(11),
                                    TextInput::make('current_city_municipality')->label('City or municipality')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('current_city_municipality'))->required(fn (): bool => ! $this->savingDraft)->maxLength(120),
                                    TextInput::make('current_province')->label('Province')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('current_province'))->required(fn (): bool => ! $this->savingDraft)->maxLength(120),
                                    TextInput::make('current_barangay')->label('Barangay (optional)')->maxLength(120)->disabled(fn (): bool => ! $this->fieldIsEditable('current_barangay')),
                                    TextInput::make('current_street_address')->label('House/unit and street (optional)')->maxLength(160)->disabled(fn (): bool => ! $this->fieldIsEditable('current_street_address')),
                                    TextInput::make('current_postal_code')->label('Postal code (optional)')->inputMode('numeric')->regex('/^\\d{4}$/')->maxLength(4)
                                        ->validationMessages(['regex' => 'Enter exactly four digits, including any leading zeroes.'])
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('current_postal_code')),
                                ])
                                ->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
                            Section::make('Parent, guardian or emergency contact')
                                ->description(fn (Get $get): string => $this->isMinor($get('birth_date'))
                                    ? 'You are under 18. Complete all three fields for a parent or guardian.'
                                    : 'Optional for adults. If you add a contact, complete all three fields.')
                                ->schema([
                                    TextInput::make('guardian_full_name')
                                        ->live(onBlur: true)
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('guardian_full_name'))
                                        ->required(fn (Get $get): bool => $this->guardianContactIsRequired($get))
                                        ->maxLength(160),
                                    TextInput::make('guardian_relationship')
                                        ->live(onBlur: true)
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('guardian_relationship'))
                                        ->required(fn (Get $get): bool => $this->guardianContactIsRequired($get))
                                        ->minLength(1)
                                        ->maxLength(60),
                                    TextInput::make('guardian_mobile')
                                        ->tel()
                                        ->live(onBlur: true)
                                        ->regex('/^09\d{9}$/')
                                        ->validationMessages(['regex' => 'Enter an 11-digit Philippine mobile number beginning with 09.'])
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('guardian_mobile'))
                                        ->required(fn (Get $get): bool => $this->guardianContactIsRequired($get))
                                        ->maxLength(11),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Education')
                        ->afterValidation(fn () => $this->saveBeforeContinuing())
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description(fn (): string => $this->stepDescription(3))
                        ->icon(Heroicon::OutlinedBuildingLibrary)
                        ->schema([
                            Section::make('Previous school and qualification')
                                ->schema([
                                    TextInput::make('prior_school_name')
                                        ->label('Previous school attended')
                                        ->helperText('Enter the official name of your previous school.')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_name'))
                                        ->required(fn (): bool => ! $this->savingDraft)
                                        ->maxLength(160),
                                    Select::make('prior_school_country_code')
                                        ->label('Previous school country')
                                        ->options([
                                            'PH' => 'Philippines',
                                            'ZZ' => 'Outside the supported Philippine Applicant path',
                                        ])
                                        ->helperText(fn (): HtmlString => $this->contactSupportLink('School or qualification not listed?'))
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_country_code'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('credential_basis')
                                        ->label('Admission qualification')
                                        ->helperText('Choose the qualification supporting your application for first-time college entry.')
                                        ->hidden(fn (Get $get): bool => $get('application_path') === AdmissionApplication::PathTransferee)
                                        ->dehydratedWhenHidden()
                                        ->options(fn (Get $get): array => $this->credentialBasisOptions((string) $get('application_path')))
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('credential_basis'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    TextEntry::make('transfer_admission_qualification')
                                        ->label('Admission qualification')
                                        ->state(AdmissionApplication::admissionQualificationOptions(AdmissionApplication::PathTransferee)[AdmissionApplication::CredentialTransfer])
                                        ->helperText('Transfer credentials are documents from your previous college supporting your transfer application.')
                                        ->visible(fn (Get $get): bool => $get('application_path') === AdmissionApplication::PathTransferee),
                                    TextInput::make('prior_school_address')
                                        ->label('School location/address (optional)')->maxLength(160)
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_address')),
                                    TextInput::make('prior_school_completion_year')
                                        ->label(fn (Get $get): string => $get('application_path') === AdmissionApplication::PathTransferee ? 'Graduation year (optional)' : 'Graduation year')
                                        ->inputMode('numeric')->extraInputAttributes(['maxlength' => 4])
                                        ->rules(['integer', 'regex:/^\\d{4}$/', 'max:'.now('Asia/Manila')->year])
                                        ->validationMessages([
                                            'integer' => 'Enter a four-digit graduation year.',
                                            'regex' => 'Enter a four-digit graduation year.',
                                            'max' => 'Graduation year cannot be later than '.now('Asia/Manila')->year.'.',
                                        ])
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_completion_year'))
                                        ->required(fn (Get $get): bool => ! $this->savingDraft && $get('application_path') !== AdmissionApplication::PathTransferee),
                                    Radio::make('lrn_availability')->label('LRN availability')
                                        ->options(['Provided' => 'Provided — I have my LRN', 'NotIssued' => 'Not issued', 'NotAvailable' => 'Not available'])
                                        ->live()->afterStateUpdated(function (Set $set, ?string $state): void {
                                            if ($state !== 'Provided') {
                                                $set('lrn', null);
                                            }
                                        })
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('lrn_availability'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    TextInput::make('lrn')->label('LRN')->inputMode('numeric')
                                        ->regex('/^\\d{12}$/')->maxLength(12)
                                        ->validationMessages(['regex' => 'Enter exactly 12 digits, including leading zeroes.'])
                                        ->visible(fn (Get $get): bool => $get('lrn_availability') === 'Provided')
                                        ->required(fn (Get $get): bool => ! $this->savingDraft && $get('lrn_availability') === 'Provided')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('lrn')),
                                    TextInput::make('prior_college_identifier')
                                        ->label('Prior-college identifier (when available)')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_college_identifier'))
                                        ->maxLength(64)
                                        ->visible(fn (Get $get): bool => $get('application_path') === AdmissionApplication::PathTransferee),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Review copies')
                        ->afterValidation(fn () => $this->saveBeforeContinuing())
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description(fn (): string => $this->stepDescription(4))
                        ->icon(Heroicon::OutlinedPaperClip)
                        ->schema(fn (Get $get): array => [
                            Section::make('Upload review copies')
                                ->description(fn (): string => $this->isCorrectionMode()
                                    ? 'Review the marked copies and replace those requested by the Registrar. Other submitted copies stay unchanged.'
                                    : 'Upload the required private copies, or keep copies you have already saved.')
                                ->schema([
                                    Checkbox::make('privacy_acknowledged')
                                        ->label('I acknowledge the current privacy notice for this application.')
                                        ->helperText('The school uses these details and private copies to review your application and check your identity records.')
                                        ->live()->accepted(fn (): bool => ! $this->savingDraft)->required(fn (): bool => ! $this->savingDraft),
                                    ...$this->evidenceFields(
                                        (int) $get('admission_cycle_id'),
                                        (string) $get('application_path'),
                                    )])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make($this->preparesDraftForApplicant() ? 'Review draft' : 'Review and submit')
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description(fn (): string => $this->stepDescription(5))
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->schema([
                            Section::make($this->preparesDraftForApplicant() ? 'Check the Applicant draft' : 'Check your application before submitting')
                                ->extraAttributes(['class' => 'tala-review-summary'])
                                ->schema([
                                    TextEntry::make('review_summary')
                                        ->label($this->preparesDraftForApplicant() ? 'Before saving' : ($this->isCorrectionMode() ? 'Before resubmitting' : 'Before submitting'))
                                        ->extraAttributes(['class' => 'tala-review-introduction'])
                                        ->columnSpanFull()
                                        ->state(fn (): string => $this->preparesDraftForApplicant()
                                            ? 'Check the answers and copies, then save the draft. The Applicant reviews the declarations and submits from their own account.'
                                            : ($this->isStudentTypeCorrection()
                                            ? 'Check the marked changes and selected files. Resubmit them together; your previous submission stays unchanged until the update succeeds.'
                                            : ($this->isCorrectionMode()
                                            ? 'Check the requested changes, then resubmit. Your application reference stays the same; your previous submission stays unchanged until the correction succeeds.'
                                            : ($this->isSubmittedEdit()
                                            ? 'Check your answers and files. Submit the complete update before Registrar review starts; your previous submission stays unchanged until it succeeds.'
                                            : 'Check your answers and files, then confirm that they are accurate. You will receive a reference after submission.')))),
                                    TextEntry::make('review_choice')->label('Application choice')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => [
                                            $this->programOptions((int) $get('admission_cycle_id'), (string) $get('application_path'))[(int) $get('program_id')] ?? 'Program not selected',
                                            AdmissionCycle::studentTypeLabel((string) $get('application_path')),
                                        ]),
                                    TextEntry::make('review_identity')->label('Personal information')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => [
                                            collect([$get('first_name'), $get('middle_name'), $get('last_name'), $get('extension_name')])->filter()->implode(' '),
                                            'Date of birth: '.$get('birth_date'),
                                            'Citizenship: '.($get('citizenship_country_code') === 'PH' ? 'Philippines' : $get('citizenship_country_code')),
                                        ]),
                                    TextEntry::make('review_contact')->label('Contact and address')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => array_values(array_filter([
                                            $get('email'), $get('phone'),
                                            collect([$get('current_street_address'), $get('current_barangay'), $get('current_city_municipality'), $get('current_province'), $get('current_postal_code')])->filter()->implode(', '),
                                        ]))),
                                    TextEntry::make('review_education')->label('Prior education')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => array_values(array_filter([
                                            $get('prior_school_name'), $get('prior_school_address'),
                                            'Admission qualification: '.($this->credentialBasisOptions((string) $get('application_path'))[$get('credential_basis')] ?? 'Not selected'),
                                            filled($get('prior_school_completion_year')) ? 'Graduation year: '.$get('prior_school_completion_year') : null,
                                            'LRN: '.str((string) $get('lrn_availability'))->headline().(filled($get('lrn')) ? ' — '.$get('lrn') : ''),
                                        ]))),
                                    TextEntry::make('review_guardian')->label('Parent, guardian or emergency contact')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => collect([$get('guardian_full_name'), $get('guardian_relationship'), $get('guardian_mobile')])->filter()->values()->all() ?: ['Not provided']),
                                    TextEntry::make('review_optional_identity')->label('Optional identity details')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => $get('optional_identity_consent') === true
                                            ? collect([$get('gender'), $get('civil_status')])->filter()->values()->all() ?: ['Consent given; no optional values provided']
                                            : ['Not provided; consent declined']),
                                    TextEntry::make('review_copies')->label('Review copies')->listWithLineBreaks()
                                        ->columnSpanFull()
                                        ->state(fn (Get $get): array => $this->reviewCopySummary($get)),
                                    Checkbox::make('accuracy_declared')
                                        ->visible(fn (): bool => ! $this->preparesDraftForApplicant())
                                        ->columnSpanFull()
                                        ->extraFieldWrapperAttributes(['class' => 'tala-review-declaration'])
                                        ->label('I declare that the submitted information and preliminary evidence are accurate to the best of my knowledge.')
                                        ->accepted(fn (): bool => ! $this->savingDraft)
                                        ->required(fn (): bool => ! $this->savingDraft),
                                ])
                                ->columns(['default' => 1, 'md' => 2])
                                ->columnSpanFull(),
                        ]),
                ])
                    ->visible(fn (): bool => $this->isWizardVisible())
                    ->nextAction(fn (Action $action): Action => $action->label($this->isReadOnlyDraftInspection() || $this->requiresCompleteSubmission() ? 'Continue' : 'Save and continue')->icon(Heroicon::OutlinedArrowRight)->color('primary'))
                    ->previousAction(fn (Action $action): Action => $action->label('Previous step')->icon(Heroicon::OutlinedArrowLeft)->button()->color('gray')->outlined())
                    ->skippable(fn (): bool => $this->isReadOnlyDraftInspection())
                    ->startOnStep(fn (): int => $this->resumeStep())
                    ->reachedStep(fn (): int => $this->resumeStep())
                    ->submitAction(($this->preparesDraftForApplicant() ? $this->draftWorkIsAvailable() : $this->submissionIsAvailable())
                        ? view('filament.applicant.components.application-submit-action', [
                            'formSubmitAction' => $this->preparesDraftForApplicant() ? 'saveDraft' : 'submitApplication',
                        ])
                        : null)
                    ->columnSpanFull(),
            ])
            ->statePath('data');

        return $schema;
    }

    protected function getViewData(): array
    {
        if ($this->isCorrectionMode()) {
            $fieldKeys = $this->activeCorrectionRequest()?->items
                ->where('scope_type', ApplicationCorrectionItem::ScopeField)->pluck('scope_key')->all() ?? [];
            $evidenceIds = $this->editableEvidenceRequirementIds();

            foreach ($this->form->getFlatComponents(withHidden: true) as $component) {
                if (! $component instanceof Field) {
                    continue;
                }

                $name = $component->getName();
                if (in_array($name, $fieldKeys, true)
                    || (str_starts_with($name, 'evidence.') && in_array((int) substr($name, 9), $evidenceIds, true))) {
                    $component->hint($name === 'application_path' && $this->isStudentTypeCorrection() ? 'Requested type' : 'Correction requested')
                        ->hintColor('warning')
                        ->extraFieldWrapperAttributes(['data-correction-requested' => true], merge: true);
                }
            }
        }

        return [
            ...parent::getViewData(),
            'formSubmitAction' => $this->preparesDraftForApplicant() ? 'saveDraft' : 'submitApplication',
            'exitUrl' => $this->afterSaveAndExitUrl(),
            'exitLocation' => $this->failureRecoveryLocation(),
            'assistanceUnavailableReason' => null,
        ];
    }

    public function isWizardVisible(): bool
    {
        return true;
    }

    protected function isReadOnlyDraftInspection(): bool
    {
        return $this->actingUser()?->id === $this->applicationOwner()?->id
            && $this->currentApplication()?->application_state === AdmissionApplication::StateDraft
            && ! $this->draftWorkIsAvailable();
    }

    public function failureRecoveryLocation(): string
    {
        return 'Home';
    }

    protected function nonDraftRedirectUrl(AdmissionApplication $source): string
    {
        return Requirements::getUrl(['application' => $source->id]);
    }

    public function saveDraft(): void
    {
        if ($this->persistDraft()) {
            $this->redirect(static::getUrl($this->continuationUrlParameters()), navigate: true);
        }
    }

    public function saveAndExit(): void
    {
        if ($this->persistDraft()) {
            $this->redirect($this->afterSaveAndExitUrl(), navigate: true);
        }
    }

    protected function saveBeforeContinuing(): void
    {
        if ($this->requiresCompleteSubmission()) {
            $this->saveStatusMessage = 'Changes remain on this page. Review all answers and submit the complete update.';

            return;
        }
        if (! $this->persistDraft()) {
            throw new Halt;
        }
    }

    protected function persistDraft(): bool
    {
        if ($this->isStudentTypeCorrection()) {
            $this->saveStatusMessage = 'The named type correction remains on this page. Review and submit its complete answers and copies together.';

            return false;
        }
        if ($this->isSubmittedEdit()) {
            $this->submitApplication();

            return false;
        }
        $this->savingDraft = true;
        $this->saveStatus = 'saving';
        $this->saveStatusMessage = 'Saving draft…';
        $factsSaved = false;

        try {
            if (! $this->draftWorkIsAvailable()) {
                throw ValidationException::withMessages([$this->currentApplication()?->application_state === AdmissionApplication::StateSubmitted ? 'application_state' : 'admission_cycle_id' => $this->currentApplication()?->application_state === AdmissionApplication::StateSubmitted
                    ? 'Registrar review started or the submitted source changed. Your current submitted version is unchanged. Keep entered work here, then open Home for the current instruction; only a named correction can reopen reviewed facts.'
                    : 'This application cycle is closed or cancelled. Inspect or discard the Draft, or contact the Registrar for an authorized extension.']);
            }
            $state = $this->form->getState();
            $submittedEdit = $this->isSubmittedEdit();
            $application = $this->saveApplication($state);
            $factsSaved = true;
            $this->sourceApplicationId = $application->id;
            $this->resolvedApplication = $application;
            $this->applicationResolved = true;
            if (! $submittedEdit) {
                $this->persistEvidence($application, (array) ($state['evidence'] ?? []));
            }
            $this->resolvedApplication = $application->refresh()->load(['correctionRequests.items', 'evidenceVersions']);
            $this->saveStatus = 'saved';
            $this->saveStatusMessage = ($this->isCorrectionMode() ? 'Correction progress saved at ' : 'Draft saved at ').now(config('app.display_timezone'))->format('g:i A').'.';
            $this->dispatch('draft-saved');

            return true;
        } catch (ValidationException $exception) {
            $this->showValidationErrors($exception);
            $this->saveStatus = 'failed';
            $this->saveStatusMessage = $factsSaved
                ? 'Save incomplete. Some answers may have saved before a copy failed. Your entered work remains here; correct the identified item before continuing.'
                : 'Draft could not be saved. Your entered work remains here; correct the identified item before continuing.';
            Notification::make()
                ->title('Draft could not be saved')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        } catch (Throwable $exception) {
            report($exception);
            $recoveryLocation = $this->failureRecoveryLocation();
            $this->saveStatus = 'failed';
            $this->saveStatusMessage = $factsSaved
                ? "Save incomplete. Some answers may have saved before a copy failed. Your entered work remains here; check {$recoveryLocation} before trying again."
                : "Draft could not be saved. Your entered work remains here; check {$recoveryLocation} before trying again.";
            Notification::make()
                ->title('Draft could not be saved')
                ->body($this->saveStatusMessage)
                ->danger()
                ->send();
        } finally {
            $this->savingDraft = false;
        }

        return false;
    }

    public function submitApplication(): void
    {
        $this->savingDraft = false;
        $this->submissionCommitted = false;
        $this->submissionOperationId = (string) Str::uuid();
        $application = null;

        try {
            if ($this->redirectToRecordedSubmission()) {
                return;
            }

            if (! $this->draftWorkIsAvailable()) {
                throw ValidationException::withMessages([$this->currentApplication()?->application_state === AdmissionApplication::StateSubmitted ? 'application_state' : 'admission_cycle_id' => $this->currentApplication()?->application_state === AdmissionApplication::StateSubmitted
                    ? 'Registrar review started or the submitted source changed. Your current submitted version is unchanged. Keep entered work here, then open Home for the current instruction; only a named correction can reopen reviewed facts.'
                    : 'This application cycle is closed or cancelled. Inspect or discard the Draft, or contact the Registrar for an authorized extension.']);
            }
            $state = $this->form->getState();
            $submittedEdit = $this->requiresCompleteSubmission();
            $application = $this->saveApplication($state);
            $this->sourceApplicationId = $application->id;
            $this->submissionCommitted = $submittedEdit;
            if (! $submittedEdit) {
                $this->persistEvidence($application, (array) ($state['evidence'] ?? []));
            }
            $applicant = Auth::user();
            abort_unless($applicant instanceof User && $this->submissionIsAvailable(), 403);
            if (! $submittedEdit) {
                $application = app(SubmitAdmissionApplication::class)->execute(
                    $application,
                    $applicant,
                    filled($state['requirement_set_id'] ?? null)
                        ? (int) $state['requirement_set_id']
                        : null,
                    operationId: $this->submissionOperationId,
                );
                $this->submissionCommitted = true;
            }
            Notification::make()
                ->title('Application submitted')
                ->body('Your stable Application reference and version-bound acknowledgment are now available.')
                ->success()
                ->send();
            $this->dispatch('draft-saved');
            $this->redirect(SubmissionReceipt::getUrl(['application' => $application->id, 'version' => $application->current_submission_version_id]), navigate: true);
        } catch (ValidationException $exception) {
            if ($this->redirectToRecordedSubmission()) {
                return;
            }

            $this->showValidationErrors($exception);
            $this->saveStatus = 'failed';
            $this->saveStatusMessage = $exception->validator->errors()->first();
            Notification::make()
                ->title('Application could not be submitted')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        } catch (Throwable $exception) {
            report($exception);
            if ($this->redirectToRecordedSubmission(followUpFailed: true)) {
                return;
            }

            $this->saveStatus = 'failed';
            $this->saveStatusMessage = 'TALA did not create a submitted version. Your persisted Application and evidence remain available; try again or contact the Registrar.';
            Notification::make()
                ->title('Application could not be submitted')
                ->body('TALA did not create a submitted version. Your last persisted Draft and evidence remain available; try again or contact the Registrar.')
                ->danger()
                ->send();
        }
    }

    private function redirectToRecordedSubmission(bool $followUpFailed = false): bool
    {
        $applicant = Auth::user();

        if (! $applicant instanceof User || $this->sourceApplicationId === null) {
            return false;
        }

        $submitted = AdmissionApplication::query()
            ->canonical()
            ->whereKey($this->sourceApplicationId)
            ->whereBelongsTo($applicant, 'user')
            ->whereNotNull('current_submission_version_id')
            ->first();

        if (! $submitted instanceof AdmissionApplication) {
            return false;
        }

        $ownSubmission = $this->submissionOperationId === null ? null : $submitted->events()
            ->where('actor_id', $applicant->id)->where('payload->operation_id', $this->submissionOperationId)
            ->latest('id')->first();
        if (! $this->submissionCommitted && $ownSubmission === null) {
            return false;
        }

        $this->resolvedApplication = $submitted->load(['correctionRequests.items', 'evidenceVersions']);
        $this->applicationResolved = true;
        $this->saveStatus = 'saved';
        $this->saveStatusMessage = $followUpFailed
            ? 'Your Application was submitted. A follow-up step could not finish. Open Home to check your submitted version and notification status.'
            : 'Your Application has a submitted version. Open Home to check its current status.';
        $notification = Notification::make()->body($this->saveStatusMessage);
        if ($followUpFailed) {
            $notification->title('Application submitted; follow-up needs attention')->warning();
        } else {
            $notification->title('Application submission recorded')->success();
        }
        $notification->send();
        $this->dispatch('draft-saved');
        $this->redirect(SubmissionReceipt::getUrl(['application' => $submitted->id, 'version' => $ownSubmission !== null ? $ownSubmission->source_id : $submitted->current_submission_version_id]), navigate: true);

        return true;
    }

    public function currentApplication(): ?AdmissionApplication
    {
        if ($this->applicationResolved) {
            return $this->resolvedApplication;
        }

        $applicant = $this->applicationOwner();

        if (! $applicant instanceof User) {
            return null;
        }

        $this->applicationResolved = true;
        $this->resolvedApplication = AdmissionApplication::query()
            ->canonical()
            ->with(['correctionRequests.items', 'evidenceVersions'])
            ->whereBelongsTo($applicant, 'user')
            ->when($this->sourceApplicationId !== null, fn (Builder $query): Builder => $query->whereKey($this->sourceApplicationId))
            ->when($this->sourceApplicationId === null && $this->entryProgramId !== null, fn (Builder $query): Builder => $query
                ->where('program_id', $this->entryProgramId)
                ->whereHas('admissionCycle', fn (Builder $cycle): Builder => $cycle->where('state', AdmissionCycle::StatePublished)
                    ->where('opens_at', '<=', now())->where('closes_at', '>', now())))
            ->whereIn('application_state', $this->sourceApplicationId !== null ? [
                AdmissionApplication::StateDraft,
                AdmissionApplication::StateActionNeeded,
                AdmissionApplication::StateSubmitted,
            ] : [AdmissionApplication::StateDraft, AdmissionApplication::StateActionNeeded])
            ->latest('id')
            ->first();

        abort_if($this->sourceApplicationId !== null && $this->resolvedApplication === null, 404);

        return $this->resolvedApplication;
    }

    public function admissionsAreOpen(): bool
    {
        return AdmissionCycle::query()
            ->when(($this->currentApplication() instanceof AdmissionApplication ? $this->currentApplication()->admission_cycle_id : ($this->data['admission_cycle_id'] ?? null)), fn (Builder $query, int $cycleId): Builder => $query->whereKey($cycleId))
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->exists();
    }

    public function hasExistingDraft(): bool
    {
        return $this->currentApplication() instanceof AdmissionApplication;
    }

    protected function saveApplication(array $state): AdmissionApplication
    {
        $applicant = $this->applicationOwner();
        $actor = $this->actingUser();
        abort_unless($applicant instanceof User && $actor instanceof User, 403);
        $application = $this->currentApplication();
        $cycle = $application instanceof AdmissionApplication || $actor->id !== $applicant->id
            ? AdmissionCycle::query()->findOrFail($application instanceof AdmissionApplication ? $application->admission_cycle_id : (int) ($state['admission_cycle_id'] ?? 0))
            : app(ResolveAdmissionEntryCycle::class)->resolve((int) ($state['program_id'] ?? 0), (string) ($state['application_path'] ?? ''));
        $payload = collect($state)
            ->except([
                'evidence',
                'email',
                'requirement_set_id',
                'assistance_reason',
                'assistance_authority_reference',
                'assistance_evidence_reference',
            ])
            ->all();
        $assistance = $this->assistanceData();

        return app(SaveAdmissionApplication::class)->execute(
            $applicant,
            $cycle,
            $payload,
            $application,
            $actor->id === $applicant->id ? null : $actor,
            $assistance['reason'],
            $assistance['authority_reference'],
            $assistance['evidence_reference'],
            $this->requiresCompleteSubmission() ? array_filter((array) ($state['evidence'] ?? []), fn (mixed $file): bool => $file !== null && $file !== '') : [],
            $this->mountedSubmissionVersionId ?? 0,
            filled($state['requirement_set_id'] ?? null) ? (int) $state['requirement_set_id'] : null,
            $this->submissionOperationId,
            $this->isStudentTypeCorrection(),
        );
    }

    /** @param array<int|string, mixed> $evidence */
    protected function persistEvidence(AdmissionApplication $application, array $evidence): void
    {
        $actor = $this->actingUser();
        abort_unless($actor instanceof User, 403);

        foreach ($evidence as $requirementId => $file) {
            if ($file === null || $file === '') {
                continue;
            }
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(["evidence.{$requirementId}" => 'Choose a new private copy, or keep the current saved upload. An existing file reference cannot attach another record’s copy.']);
            }

            $requirement = AdmissionRequirement::query()->findOrFail((int) $requirementId);
            $existing = $application->evidenceVersions()
                ->where('admission_requirement_id', $requirement->id)
                ->latest('id')
                ->first();

            if ($existing) {
                app(AdmissionEvidenceService::class)->replace($existing, $actor, $file);
            } else {
                app(AdmissionEvidenceService::class)->store($application, $requirement, $actor, $file);
            }

            $this->form->getComponentByStatePath("evidence.{$requirementId}")?->state(null);
        }
    }

    /** @return list<Section|Placeholder> */
    protected function evidenceFields(int $cycleId, string $path): array
    {
        if ($cycleId < 1 || ! in_array($path, [AdmissionApplication::PathFirstYear, AdmissionApplication::PathTransferee], true)) {
            return [
                Placeholder::make('choose_scope_first')
                    ->content('Choose a Program and Student type before adding requirements.'),
            ];
        }

        try {
            $set = $this->requirementSetForFormScope($cycleId, $path);
            $set->loadMissing('requirements');
        } catch (ValidationException) {
            $set = null;
        }

        if (! $set instanceof AdmissionRequirementSet) {
            return [
                Placeholder::make('requirements_unavailable')
                    ->content('The published Requirement Set is unavailable. Save no submission and ask the Registrar to correct the cycle.'),
            ];
        }

        $requirements = $set->requirements
            ->where('requires_preliminary_evidence', true)
            ->sortBy('display_order');

        if ($this->isCorrectionMode() && ! $this->isStudentTypeCorrection()) {
            $requirements = $requirements->whereIn('id', $this->editableEvidenceRequirementIds());
        }

        $fields = $requirements
            ->map(fn (AdmissionRequirement $requirement): Section => Section::make($requirement->label)->schema([
                TextEntry::make('retained_copy_'.$requirement->id)->label('Current copy')
                    ->state(function () use ($requirement): string {
                        $copy = $this->currentEvidence($requirement);
                        if ($copy !== null && $this->isStudentTypeCorrection()) {
                            return 'Retained private copy. Registrar will review it against the corrected application.';
                        }
                        $review = $copy?->preliminaryReviews()->whereDoesntHave('successor')->first();

                        return $copy === null ? 'No copy saved yet.'
                            : ($review === null ? 'Retained — awaiting Registrar review' : ($review->result === PreliminaryEvidenceReview::ResultAccepted ? 'Retained — review copy accepted' : ucfirst(str($review->result)->headline()->lower()->toString())));
                    }),
                Actions::make([
                    Action::make('viewCurrentCopy'.$requirement->id)->label('View current copy')->icon(Heroicon::OutlinedEye)
                        ->extraAttributes(['aria-label' => 'View '.$requirement->label.' current private copy'])
                        ->url(fn (): ?string => ($copy = $this->currentEvidence($requirement)) ? route('admissions.evidence.view', $copy) : null)->openUrlInNewTab(),
                    Action::make('downloadCurrentCopy'.$requirement->id)->label('Download')->icon(Heroicon::OutlinedArrowDownTray)
                        ->extraAttributes(['aria-label' => 'Download '.$requirement->label.' current private copy'])
                        ->url(fn (): ?string => ($copy = $this->currentEvidence($requirement)) ? route('admissions.evidence.download', $copy) : null),
                ])->visible(fn (): bool => $this->currentEvidence($requirement) !== null),
                FileUpload::make("evidence.{$requirement->id}")
                    ->label(fn (): string => $this->hasEvidence($requirement) ? 'Replacement copy' : 'Upload copy')
                    ->hint(fn (): ?string => $this->isCorrectionMode() ? 'Correction requested' : ($this->hasEvidence($requirement) ? 'Keep the current copy, or choose a replacement.' : null))
                    ->hintColor(fn (): string => $this->isCorrectionMode() ? 'warning' : 'gray')
                    ->placeholder('Drop a file or <span class="filepond--label-action">Choose '.e($requirement->label).'</span>')
                    ->helperText($requirement->purpose.' '.(str_contains(strtolower($requirement->label), 'photo') ? 'JPEG or PNG' : 'PDF, JPEG, or PNG').'; maximum 10 MiB.')
                    ->acceptedFileTypes(str_contains(strtolower($requirement->label), 'photo')
                        ? ['image/jpeg', 'image/png']
                        : ['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(10240)
                    ->maxFiles(1)
                    ->disabled(fn (): bool => ! $this->draftWorkIsAvailable() || ($this->data['privacy_acknowledged'] ?? false) !== true
                        || ($this->isCorrectionMode() && ! in_array($requirement->id, $this->editableEvidenceRequirementIds(), true)))
                    ->storeFiles(false)
                    ->required(fn (): bool => ! $this->savingDraft && ! $this->hasEvidence($requirement)),
            ]))
            ->values()
            ->all();

        return $fields !== [] ? $fields : [
            Placeholder::make('no_preliminary_uploads')
                ->label($this->isCorrectionMode() ? 'Current copies' : 'No preliminary uploads')
                ->content($this->isCorrectionMode()
                    ? 'No review-copy replacement was requested. Previously submitted copies remain unchanged.'
                    : 'This Requirement Set has no preliminary digital upload. Official credential instructions remain visible after submission.'),
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

    private function currentEvidence(AdmissionRequirement $requirement): ?DocumentEvidence
    {
        return $this->currentApplication()?->evidenceVersions->where('admission_requirement_id', $requirement->id)->sortByDesc('id')->first();
    }

    /** @return list<string> */
    protected function reviewCopySummary(Get $get): array
    {
        try {
            $set = $this->requirementSetForFormScope((int) $get('admission_cycle_id'), (string) $get('application_path'));
        } catch (ValidationException) {
            return ['The published Requirement Set is unavailable. Ask the Registrar to correct the cycle.'];
        }

        $requirements = $set->requirements->where('requires_preliminary_evidence', true)->sortBy('display_order');

        if ($this->isCorrectionMode() && ! $this->isStudentTypeCorrection()) {
            $requirements = $requirements->whereIn('id', $this->editableEvidenceRequirementIds());
        }

        $application = $this->currentApplication();

        return $requirements->map(function (AdmissionRequirement $requirement) use ($get, $application): string {
            $selection = $get("evidence.{$requirement->id}");
            $file = collect(is_array($selection) ? $selection : [$selection])
                ->first(fn (mixed $candidate): bool => $candidate instanceof UploadedFile);
            $saved = $application?->evidenceVersions->sortByDesc('id')->firstWhere('admission_requirement_id', $requirement->id);

            if ($file instanceof UploadedFile) {
                return $requirement->label.': '.($saved ? 'Replacement selected' : 'New file selected').' — '.$file->getClientOriginalName();
            }

            return $requirement->label.': '.($saved ? 'Saved review copy' : 'Not provided');
        })->values()->all() ?: [$this->isCorrectionMode()
            ? 'No review-copy replacement was requested. Previously submitted copies remain unchanged.'
            : 'This Requirement Set has no preliminary digital upload. Official credential instructions remain visible after submission.'];
    }

    public function isCorrectionMode(): bool
    {
        return $this->currentApplication()?->application_state === AdmissionApplication::StateActionNeeded;
    }

    public function isStudentTypeCorrection(): bool
    {
        return $this->activeCorrectionRequest()?->changesStudentType() ?? false;
    }

    public function requiresCompleteSubmission(): bool
    {
        return $this->isSubmittedEdit() || $this->isStudentTypeCorrection();
    }

    public function activeCorrectionRequest(): ?ApplicationCorrectionRequest
    {
        return $this->currentApplication()?->correctionRequests
            ->where('state', ApplicationCorrectionRequest::StateActive)
            ->sortByDesc('requested_at')
            ->first();
    }

    /** @return array<int, array{label: string, items: list<string>}> */
    public function correctionSteps(): array
    {
        $request = $this->activeCorrectionRequest();
        if (! $request instanceof ApplicationCorrectionRequest) {
            return [];
        }

        $request->loadMissing('items.admissionRequirement');
        $labels = [1 => 'Program and type', 2 => 'Identity and contact', 3 => 'Education', 4 => 'Review copies', 5 => 'Review and submit'];
        $fields = ApplicationCorrectionItem::fieldOptions();
        $steps = [];

        foreach ($request->items as $item) {
            $step = $this->correctionItemStep($item);
            $steps[$step] ??= ['label' => $labels[$step], 'items' => []];
            $steps[$step]['items'][] = $item->scope_type === ApplicationCorrectionItem::ScopeEvidence
                ? ($item->admissionRequirement->label ?? 'Requested file')
                : ($item->scope_key === 'accuracy_declared' ? 'Confirm updated answers' : ($fields[$item->scope_key] ?? str($item->scope_key)->headline()->toString()));
        }

        ksort($steps);

        return $steps;
    }

    private function correctionItemStep(ApplicationCorrectionItem $item): int
    {
        if ($item->scope_type === ApplicationCorrectionItem::ScopeEvidence) {
            return 4;
        }

        return match ($item->scope_key) {
            'application_path', 'program_id' => 1,
            'first_name', 'middle_name', 'last_name', 'extension_name', 'birth_date',
            'citizenship_country_code', 'phone', 'current_city_municipality', 'current_province',
            'guardian_full_name', 'guardian_relationship', 'guardian_mobile',
            'gender', 'civil_status', 'optional_identity_consent', 'current_barangay', 'current_street_address', 'current_postal_code' => 2,
            'prior_school_name', 'prior_school_country_code', 'prior_school_completion_year',
            'credential_basis', 'lrn', 'lrn_availability', 'prior_college_identifier', 'prior_school_address' => 3,
            'privacy_acknowledged' => 4,
            default => 5,
        };
    }

    private function stepDescription(int $step): string
    {
        $count = count($this->correctionSteps()[$step]['items'] ?? []);

        return "Step {$step} of 5".($count > 0 ? ' · '.$count.' '.($count === 1 ? 'correction' : 'corrections') : '');
    }

    private function contactSupportLink(string $context): HtmlString
    {
        return new HtmlString(e($context).' <a class="fi-link fi-size-sm fi-color-primary" href="'.e(route('home').'#support').'" target="_blank" rel="noopener">Contact support<span class="fi-sr-only"> (opens in a new tab)</span></a>');
    }

    private function guardianContactIsRequired(Get $get): bool
    {
        return ! $this->savingDraft && ($this->isMinor($get('birth_date'))
            || collect(['guardian_full_name', 'guardian_relationship', 'guardian_mobile'])
                ->contains(fn (string $field): bool => filled($get($field))));
    }

    private function fieldIsEditable(string $field): bool
    {
        if (! $this->draftWorkIsAvailable()) {
            return false;
        }
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
    protected function programOptions(int $cycleId, string $path): array
    {
        if ($cycleId < 1) {
            $flag = $path === AdmissionApplication::PathTransferee ? 'accepts_transferee' : 'accepts_first_year';

            return Program::query()->where('is_active', true)->whereHas('admissionCycles', fn (Builder $query): Builder => $query
                ->where('state', AdmissionCycle::StatePublished)->where('opens_at', '<=', now())->where('closes_at', '>', now())
                ->whereHas('term', fn (Builder $term): Builder => $term->where('state', Term::StateActive))
                ->when(in_array($path, [AdmissionApplication::PathFirstYear, AdmissionApplication::PathTransferee], true), fn (Builder $cycle): Builder => $cycle->where('admission_cycle_program.'.$flag, true)))
                ->orderBy('name')->pluck('name', 'id')->all();
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
        return AdmissionApplication::admissionQualificationOptions($path);
    }

    private function showValidationErrors(ValidationException $exception): void
    {
        $this->setErrorBag(collect($exception->errors())->mapWithKeys(
            fn (array $messages, string $key): array => [
                str_starts_with($key, 'data.') ? $key : 'data.'.$key => $messages,
            ],
        )->all());
    }

    public function updatingData(mixed $value, string $key): void
    {
        if (in_array($key, ['gender', 'civil_status'], true)
            && filled($value)
            && (($this->data['optional_identity_consent'] ?? false) !== true
                || Auth::id() !== $this->applicationOwner()?->id)) {
            throw ValidationException::withMessages([
                'data.'.$key => 'Agree to the optional identity purpose before entering this detail.',
            ]);
        }
    }

    private function isMinor(mixed $birthDate): bool
    {
        if (blank($birthDate)) {
            return false;
        }

        try {
            return CarbonImmutable::parse((string) $birthDate, config('app.display_timezone'))
                ->diffInYears(CarbonImmutable::today(config('app.display_timezone'))) < 18;
        } catch (\Exception) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    protected function initialState(): array
    {
        return [
            'email' => $this->applicationOwner()?->email,
            'program_id' => $this->entryProgramId ?? session('public_entry.program_id'),
            'optional_identity_consent' => false,
            'evidence' => [],
            'requirement_set_id' => null,
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
            'lrn_availability',
            'gender',
            'civil_status',
            'current_barangay',
            'current_street_address',
            'current_postal_code',
            'prior_school_address',
            'prior_college_identifier',
        ]))->merge([
            'evidence' => $this->emptyEvidenceState(
                (int) $application->admission_cycle_id,
                (string) $application->application_path,
            ),
            'credential_basis' => $application->credential_basis ?? ($application->application_state === AdmissionApplication::StateDraft
                && $application->application_path === AdmissionApplication::PathTransferee ? AdmissionApplication::CredentialTransfer : null),
            'privacy_acknowledged' => $application->privacy_acknowledged_at !== null
                && $application->privacy_notice_reference === $application->admissionCycle?->privacy_notice_reference,
            'optional_identity_consent' => $application->optional_identity_consented_at !== null,
            'gender' => $application->optional_identity_consented_at !== null ? $application->gender : null,
            'civil_status' => $application->optional_identity_consented_at !== null ? $application->civil_status : null,
            'accuracy_declared' => false,
            ...($this->isStudentTypeCorrection() ? [
                'application_path' => $this->activeCorrectionRequest()->target_application_path,
                'credential_basis' => $this->activeCorrectionRequest()->target_application_path === AdmissionApplication::PathTransferee
                    ? AdmissionApplication::CredentialTransfer : null,
            ] : []),
            'requirement_set_id' => $this->requirementSetId(
                (int) $application->admission_cycle_id,
                (string) $application->application_path,
            ),
        ])->all();
    }

    protected function applicationOwner(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    protected function actingUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /** @return array<int, mixed> */
    protected function assistanceComponents(): array
    {
        return [];
    }

    protected function preparesDraftForApplicant(): bool
    {
        return false;
    }

    /** @return array{reason: string|null, authority_reference: string|null, evidence_reference: string|null} */
    protected function assistanceData(): array
    {
        return [
            'reason' => null,
            'authority_reference' => null,
            'evidence_reference' => null,
        ];
    }

    public function submissionIsAvailable(): bool
    {
        return $this->draftWorkIsAvailable();
    }

    public function draftWorkIsAvailable(): bool
    {
        if ($this->currentApplication()?->application_state === AdmissionApplication::StateSubmitted) {
            return $this->isSubmittedEdit();
        }
        if ($this->isCorrectionMode()) {
            return $this->activeCorrectionRequest() !== null;
        }
        $cycleId = ($this->currentApplication() instanceof AdmissionApplication ? $this->currentApplication()->admission_cycle_id : ($this->data['admission_cycle_id'] ?? null));
        if ($cycleId === null) {
            return $this->admissionsAreOpen();
        }
        $cycle = AdmissionCycle::query()->find($cycleId);

        return $cycle?->state === AdmissionCycle::StatePublished
            && $cycle->opens_at <= now()
            && $cycle->closes_at > now();
    }

    public function isSubmittedEdit(): bool
    {
        return $this->submittedEditAllowed ??= $this->actingUser()?->id === $this->applicationOwner()?->id
            && ($this->currentApplication()?->allowsSubmittedEdit() ?? false);
    }

    private function resolveEntryContext(Set $set, Get $get): void
    {
        if ($this->currentApplication() instanceof AdmissionApplication) {
            return;
        }
        $set('admission_cycle_id', null);
        if (blank($get('program_id')) || blank($get('application_path'))) {
            return;
        }
        try {
            $cycle = app(ResolveAdmissionEntryCycle::class)->resolve((int) $get('program_id'), (string) $get('application_path'));
            $set('admission_cycle_id', $cycle->id);
            $this->resetErrorBag('data.program_id');
            $this->refreshRequirementState($set, $cycle->id, (string) $get('application_path'));
        } catch (ValidationException $exception) {
            $this->showValidationErrors($exception);
        }
    }

    /** @return array<string, mixed> */
    protected function continuationUrlParameters(): array
    {
        return $this->sourceApplicationId !== null ? ['application' => $this->sourceApplicationId] : [];
    }

    protected function afterSaveAndExitUrl(): string
    {
        return Dashboard::getUrl(['application' => $this->sourceApplicationId ?? $this->currentApplication()?->id]);
    }

    protected function afterDiscardUrl(): string
    {
        return Dashboard::getUrl();
    }

    protected function requirementSetId(int $cycleId, string $path): ?int
    {
        if ($cycleId < 1 || ! in_array($path, [
            AdmissionApplication::PathFirstYear,
            AdmissionApplication::PathTransferee,
        ], true)) {
            return null;
        }

        try {
            return $this->requirementSetForFormScope($cycleId, $path)->id;
        } catch (ValidationException) {
            return null;
        }
    }

    protected function refreshRequirementState(Set $set, int $cycleId, string $path): void
    {
        $set('requirement_set_id', $this->requirementSetId($cycleId, $path));
        $set('evidence', $this->emptyEvidenceState($cycleId, $path));
    }

    protected function requirementSetForFormScope(int $cycleId, string $path): AdmissionRequirementSet
    {
        $application = $this->currentApplication();

        if ($application instanceof AdmissionApplication && $this->isStudentTypeCorrection()) {
            return app(ResolveAdmissionRequirementSet::class)->forTypeCorrection($application, $this->activeCorrectionRequest());
        }

        if ($application instanceof AdmissionApplication
            && ($application->application_state === AdmissionApplication::StateActionNeeded || $application->application_path === $path)) {
            return app(ResolveAdmissionRequirementSet::class)->forApplication($application);
        }

        return app(ResolveAdmissionRequirementSet::class)->forScope($cycleId, $path);
    }

    /** @return array<int, null> */
    protected function emptyEvidenceState(int $cycleId, string $path): array
    {
        try {
            return $this->requirementSetForFormScope($cycleId, $path)
                ->requirements()
                ->where('requires_preliminary_evidence', true)
                ->pluck('id')
                ->mapWithKeys(fn (mixed $id): array => [(int) $id => null])
                ->all();
        } catch (ValidationException) {
            return [];
        }
    }

    protected function resumeStep(): int
    {
        $application = $this->currentApplication();

        if (! $application instanceof AdmissionApplication) {
            return 1;
        }

        if ($this->isCorrectionMode()) {
            $request = $this->activeCorrectionRequest();
            $scopes = $request instanceof ApplicationCorrectionRequest
                ? $request->items
                : collect();

            return $scopes->reject(fn (ApplicationCorrectionItem $item): bool => $request?->changesStudentType()
                && $item->scope_type === ApplicationCorrectionItem::ScopeField && $item->scope_key === 'application_path')
                ->map(fn (ApplicationCorrectionItem $item): int => $this->correctionItemStep($item))
                ->min() ?? 5;
        }

        if (blank($application->admission_cycle_id)
            || blank($application->application_path)
            || blank($application->program_id)) {
            return 1;
        }

        if (collect([
            $application->first_name,
            $application->last_name,
            $application->birth_date,
            $application->citizenship_country_code,
            $application->phone,
            $application->current_city_municipality,
            $application->current_province,
        ])->contains(fn (mixed $value): bool => blank($value))) {
            return 2;
        }

        if (collect([
            $application->prior_school_name,
            $application->prior_school_country_code,
            $application->credential_basis,
            ...($application->application_path === AdmissionApplication::PathTransferee ? [] : [$application->prior_school_completion_year]),
            $application->getAttribute('lrn_availability'),
        ])->contains(fn (mixed $value): bool => blank($value))) {
            return 3;
        }

        try {
            $requirementSet = app(ResolveAdmissionRequirementSet::class)->forApplication($application);
            $requiredEvidenceIds = $requirementSet->requirements()
                ->where('requires_preliminary_evidence', true)
                ->pluck('id');
            $presentEvidenceIds = $application->evidenceVersions()
                ->whereIn('admission_requirement_id', $requiredEvidenceIds)
                ->pluck('admission_requirement_id')
                ->unique();

            if ($requiredEvidenceIds->diff($presentEvidenceIds)->isNotEmpty()) {
                return 4;
            }
        } catch (ValidationException) {
            return 4;
        }

        return 5;
    }
}
