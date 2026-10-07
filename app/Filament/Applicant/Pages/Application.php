<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Admissions\AdmissionEvidenceService;
use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Admissions\ResolveAdmissionRequirementSet;
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
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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

    /** @var array<string, mixed> | null */
    public ?array $data = [];

    #[Url(as: 'application')]
    public ?int $sourceApplicationId = null;

    public bool $savingDraft = false;

    public string $saveStatus = 'saved';

    public string $saveStatusMessage = 'Saved progress restored.';

    private bool $applicationResolved = false;

    private ?AdmissionApplication $resolvedApplication = null;

    public function mount(): void
    {
        if ($this->sourceApplicationId !== null) {
            $source = AdmissionApplication::query()->canonical()
                ->whereBelongsTo($this->applicationOwner(), 'user')
                ->findOrFail($this->sourceApplicationId);
            if (! in_array($source->application_state, [AdmissionApplication::StateDraft, AdmissionApplication::StateActionNeeded], true)) {
                $this->redirect($this->nonDraftRedirectUrl($source));

                return;
            }
        }

        $application = $this->currentApplication();
        $this->saveStatus = $application instanceof AdmissionApplication ? 'saved' : 'not-saved';
        $this->saveStatusMessage = $application instanceof AdmissionApplication
            ? 'Saved progress restored.'
            : 'Draft has not been saved yet.';
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
        $application = AdmissionApplication::query()->canonical()
            ->where('user_id', Auth::id())
            ->latest('updated_at')
            ->latest('id')
            ->first(['id', 'application_state']);

        if (! $application instanceof AdmissionApplication) {
            return static::getUrl();
        }

        return in_array($application->application_state, [
            AdmissionApplication::StateDraft,
            AdmissionApplication::StateActionNeeded,
        ], true)
            ? static::getUrl(['application' => $application->id])
            : Dashboard::getUrl(['application' => $application->id]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('saveAndExit')
                    ->label('Save and exit')
                    ->icon(Heroicon::OutlinedArrowRightOnRectangle)
                    ->color('gray')
                    ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                    ->action(function (): void {
                        if ($this->persistDraft()) {
                            $this->redirect(Dashboard::getUrl(['application' => $this->sourceApplicationId]));
                        }
                    }),
            ])->label('Application options')->icon(Heroicon::OutlinedChevronDown)->button()->color('gray')
                ->extraAttributes(['data-application-options' => true]),
            Action::make('discardDraft')
                ->modalSubmitActionLabel('Discard draft')
                ->label('Discard draft')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->outlined()
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
                    $this->redirect($this->afterDiscardUrl());
                }),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(['application' => $this->sourceApplicationId ?? $this->currentApplication()?->id]) => 'Home', 'Application'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...$this->assistanceComponents(),
                Hidden::make('requirement_set_id'),
                Wizard::make([
                    Step::make('Choice')
                        ->afterValidation(fn () => $this->saveBeforeContinuing())
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description('Step 1 of 5')
                        ->icon(Heroicon::OutlinedAcademicCap)
                        ->schema([
                            Section::make('Choose your admission cycle and program')
                                ->schema([
                                    Select::make('admission_cycle_id')
                                        ->label('Admission Cycle')
                                        ->options(fn (): array => $this->cycleOptions())
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            $this->refreshRequirementState(
                                                $set,
                                                (int) $get('admission_cycle_id'),
                                                (string) $get('application_path'),
                                            );
                                        })
                                        ->searchable()
                                        ->disabled(fn (): bool => $this->currentApplication() instanceof AdmissionApplication)
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Radio::make('application_path')
                                        ->label('Applying as')
                                        ->options([
                                            AdmissionApplication::PathFirstYear => 'First year',
                                            AdmissionApplication::PathTransferee => 'Transferee',
                                        ])
                                        ->live()
                                        ->afterStateUpdated(function (Set $set, Get $get): void {
                                            $this->refreshRequirementState(
                                                $set,
                                                (int) $get('admission_cycle_id'),
                                                (string) $get('application_path'),
                                            );
                                        })
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
                        ->afterValidation(fn () => $this->saveBeforeContinuing())
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description('Step 2 of 5')
                        ->icon(Heroicon::OutlinedIdentification)
                        ->schema([
                            Section::make('Personal information')
                                ->schema([
                                    TextInput::make('first_name')->label('First name')->disabled(fn (): bool => ! $this->fieldIsEditable('first_name'))->required(fn (): bool => ! $this->savingDraft)->maxLength(100),
                                    TextInput::make('last_name')->label('Last name')->disabled(fn (): bool => ! $this->fieldIsEditable('last_name'))->required(fn (): bool => ! $this->savingDraft)->maxLength(100),
                                    TextInput::make('middle_name')->label('Middle name (optional)')->disabled(fn (): bool => ! $this->fieldIsEditable('middle_name'))->maxLength(100),
                                    TextInput::make('extension_name')->label('Suffix (optional)')->disabled(fn (): bool => ! $this->fieldIsEditable('extension_name'))->maxLength(30),
                                    DatePicker::make('birth_date')->label('Birth date')
                                        ->native()->maxDate(now('Asia/Manila'))->live(onBlur: true)
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('birth_date'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('citizenship_country_code')->label('Citizenship')
                                        ->options(['PH' => 'Philippines', 'ZZ' => 'Foreign or another citizenship'])
                                        ->helperText('For another citizenship, contact the Registrar at '.config('institution.public.support_phone').'.')
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
                                ->description('Required below 18. For adults this contact is optional; complete all three fields if you provide one.')
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
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Education')
                        ->afterValidation(fn () => $this->saveBeforeContinuing())
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description('Step 3 of 5')
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
                                        ->helperText('If your school or qualification is outside the choices shown, contact the Registrar at '.config('institution.public.support_phone').'.')
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_country_code'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    Select::make('credential_basis')
                                        ->label('Educational attainment')
                                        ->options(fn (Get $get): array => $this->credentialBasisOptions((string) $get('application_path')))
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('credential_basis'))
                                        ->required(fn (): bool => ! $this->savingDraft),
                                    TextInput::make('prior_school_address')
                                        ->label('School location/address (optional)')->maxLength(160)
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_address')),
                                    TextInput::make('prior_school_completion_year')
                                        ->label('Graduation year')->inputMode('numeric')->extraInputAttributes(['maxlength' => 4])
                                        ->rules(['integer', 'regex:/^\\d{4}$/', 'max:'.now('Asia/Manila')->year])
                                        ->validationMessages([
                                            'integer' => 'Enter a four-digit graduation year.',
                                            'regex' => 'Enter a four-digit graduation year.',
                                            'max' => 'Graduation year cannot be later than '.now('Asia/Manila')->year.'.',
                                        ])
                                        ->disabled(fn (): bool => ! $this->fieldIsEditable('prior_school_completion_year'))
                                        ->required(fn (): bool => ! $this->savingDraft),
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
                        ->description('Step 4 of 5')
                        ->icon(Heroicon::OutlinedPaperClip)
                        ->schema(fn (Get $get): array => [
                            Section::make('Private preliminary evidence')
                                ->description('Upload the selected review copies after acknowledging the privacy notice. The school checks paper credentials externally; the Registrar records enrollment clearance separately.')
                                ->schema([
                                    Checkbox::make('privacy_acknowledged')
                                        ->label('I acknowledge the current privacy notice for this application.')
                                        ->helperText('The school uses the required intake and selected private copies for admissions and identity-record review.')
                                        ->live()->accepted(fn (): bool => ! $this->savingDraft)->required(fn (): bool => ! $this->savingDraft),
                                    ...$this->evidenceFields(
                                        (int) $get('admission_cycle_id'),
                                        (string) $get('application_path'),
                                    )])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                    Step::make('Review and submit')
                        ->disabled(fn (): bool => ! $this->draftWorkIsAvailable())
                        ->description('Step 5 of 5')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->schema([
                            Section::make('Check your application before submitting')
                                ->extraAttributes(['class' => 'tala-review-summary'])
                                ->schema([
                                    TextEntry::make('review_summary')
                                        ->label('Before submitting')
                                        ->state('Check your answers and files in every step. Submitting saves a fixed version and gives your application a permanent reference. After submission, you can change only the items the Registrar asks you to correct.'),
                                    TextEntry::make('review_choice')->label('Application choice')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => [
                                            $this->programOptions((int) $get('admission_cycle_id'), (string) $get('application_path'))[(int) $get('program_id')] ?? 'Program not selected',
                                            str((string) $get('application_path'))->headline()->toString(),
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
                                            $this->credentialBasisOptions((string) $get('application_path'))[$get('credential_basis')] ?? 'Not selected',
                                            'Completion year: '.$get('prior_school_completion_year'),
                                            'LRN: '.str((string) $get('lrn_availability'))->headline().(filled($get('lrn')) ? ' — '.$get('lrn') : ''),
                                        ]))),
                                    TextEntry::make('review_guardian')->label('Parent, guardian or emergency contact')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => collect([$get('guardian_full_name'), $get('guardian_relationship'), $get('guardian_mobile')])->filter()->values()->all() ?: ['Not provided']),
                                    TextEntry::make('review_optional_identity')->label('Optional identity details')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => $get('optional_identity_consent') === true
                                            ? collect([$get('gender'), $get('civil_status')])->filter()->values()->all() ?: ['Consent given; no optional values provided']
                                            : ['Not provided; consent declined']),
                                    TextEntry::make('review_copies')->label('Review copies')->listWithLineBreaks()
                                        ->state(fn (Get $get): array => $this->reviewCopySummary($get)),
                                    Checkbox::make('accuracy_declared')
                                        ->label('I declare that the submitted information and preliminary evidence are accurate to the best of my knowledge.')
                                        ->accepted(fn (): bool => ! $this->savingDraft)
                                        ->required(fn (): bool => ! $this->savingDraft),
                                ])
                                ->columns(1)
                                ->columnSpanFull(),
                        ]),
                ])
                    ->visible(fn (): bool => $this->isWizardVisible())
                    ->nextAction(fn (Action $action): Action => $action->label($this->isReadOnlyDraftInspection() ? 'Continue' : 'Save and continue')->icon(Heroicon::OutlinedArrowRight)->color('primary'))
                    ->previousAction(fn (Action $action): Action => $action->label('Back')->icon(Heroicon::OutlinedArrowLeft)->button()->color('gray')->outlined())
                    ->skippable(fn (): bool => $this->isReadOnlyDraftInspection())
                    ->startOnStep(fn (): int => $this->resumeStep())
                    ->submitAction($this->submissionIsAvailable()
                        ? view('filament.applicant.components.application-submit-action')
                        : null)
                    ->columnSpanFull(),
            ])
            ->statePath('data');
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
        return Dashboard::getUrl(['application' => $source->id]);
    }

    public function saveDraft(): void
    {
        if ($this->persistDraft()) {
            $this->redirect(static::getUrl($this->continuationUrlParameters()));
        }
    }

    protected function saveBeforeContinuing(): void
    {
        if (! $this->persistDraft()) {
            throw new Halt;
        }
    }

    protected function persistDraft(): bool
    {
        $this->savingDraft = true;
        $this->saveStatus = 'saving';
        $this->saveStatusMessage = 'Saving draft…';
        $factsSaved = false;

        try {
            if (! $this->draftWorkIsAvailable()) {
                throw ValidationException::withMessages(['admission_cycle_id' => 'This application cycle is closed or cancelled. Inspect or discard the Draft, or contact the Registrar for an authorized extension.']);
            }
            $state = $this->form->getState();
            $application = $this->saveApplication($state);
            $factsSaved = true;
            $this->sourceApplicationId = $application->id;
            $this->resolvedApplication = $application;
            $this->applicationResolved = true;
            $this->persistEvidence($application, (array) ($state['evidence'] ?? []));
            $this->resolvedApplication = $application->refresh()->load(['correctionRequests.items', 'evidenceVersions']);
            $this->saveStatus = 'saved';
            $this->saveStatusMessage = 'Draft saved at '.now(config('app.display_timezone'))->format('g:i A').'.';
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
        $application = null;

        try {
            if ($this->redirectToRecordedSubmission()) {
                return;
            }

            if (! $this->draftWorkIsAvailable()) {
                throw ValidationException::withMessages(['admission_cycle_id' => 'This application cycle is closed or cancelled. Inspect or discard the Draft, or contact the Registrar for an authorized extension.']);
            }
            $state = $this->form->getState();
            $application = $this->saveApplication($state);
            $this->sourceApplicationId = $application->id;
            $this->persistEvidence($application, (array) ($state['evidence'] ?? []));
            $applicant = Auth::user();
            abort_unless($applicant instanceof User && $this->submissionIsAvailable(), 403);
            app(SubmitAdmissionApplication::class)->execute(
                $application,
                $applicant,
                filled($state['requirement_set_id'] ?? null)
                    ? (int) $state['requirement_set_id']
                    : null,
            );
            Notification::make()
                ->title('Application submitted')
                ->body('Your stable Application reference and version-bound acknowledgment are now available.')
                ->success()
                ->send();
            $this->dispatch('draft-saved');
            $this->redirect(Dashboard::getUrl(['application' => $application->id]));
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
            ->where('application_state', AdmissionApplication::StateSubmitted)
            ->whereNotNull('current_submission_version_id')
            ->first();

        if (! $submitted instanceof AdmissionApplication) {
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
        $this->redirect(Dashboard::getUrl(['application' => $submitted->id]));

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
            ->whereIn('application_state', [
                AdmissionApplication::StateDraft,
                AdmissionApplication::StateActionNeeded,
            ])
            ->latest('id')
            ->first();

        abort_if($this->sourceApplicationId !== null && $this->resolvedApplication === null, 404);

        return $this->resolvedApplication;
    }

    public function admissionsAreOpen(): bool
    {
        return AdmissionCycle::query()
            ->when($this->currentApplication()?->admission_cycle_id ?? ($this->data['admission_cycle_id'] ?? null), fn (Builder $query, int $cycleId): Builder => $query->whereKey($cycleId))
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
        $cycle = AdmissionCycle::query()->findOrFail($application?->admission_cycle_id ?? (int) $state['admission_cycle_id']);
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
        );
    }

    /** @param array<int|string, mixed> $evidence */
    protected function persistEvidence(AdmissionApplication $application, array $evidence): void
    {
        $actor = $this->actingUser();
        abort_unless($actor instanceof User, 403);

        foreach ($evidence as $requirementId => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
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

    /** @return list<FileUpload|Placeholder> */
    protected function evidenceFields(int $cycleId, string $path): array
    {
        if ($cycleId < 1 || ! in_array($path, [AdmissionApplication::PathFirstYear, AdmissionApplication::PathTransferee], true)) {
            return [
                Placeholder::make('choose_scope_first')
                    ->content('Choose an Admission Cycle and path before adding evidence.'),
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

        if ($this->isCorrectionMode()) {
            $requirements = $requirements->whereIn('id', $this->editableEvidenceRequirementIds());
        }

        $fields = $requirements
            ->map(fn (AdmissionRequirement $requirement): FileUpload => FileUpload::make("evidence.{$requirement->id}")
                ->label($requirement->label)
                ->placeholder('Drop a file or <span class="filepond--label-action">Choose '.e($requirement->label).'</span>')
                ->helperText($requirement->purpose.' '.(str_contains(strtolower($requirement->label), 'photo') ? 'JPEG or PNG' : 'PDF, JPEG, or PNG').'; maximum 10 MiB.')
                ->acceptedFileTypes(str_contains(strtolower($requirement->label), 'photo')
                    ? ['image/jpeg', 'image/png']
                    : ['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(10240)
                ->maxFiles(1)
                ->disabled(fn (): bool => ! $this->draftWorkIsAvailable() || ($this->data['privacy_acknowledged'] ?? false) !== true)
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

    /** @return list<string> */
    protected function reviewCopySummary(Get $get): array
    {
        try {
            $set = $this->requirementSetForFormScope((int) $get('admission_cycle_id'), (string) $get('application_path'));
        } catch (ValidationException) {
            return ['The published Requirement Set is unavailable. Ask the Registrar to correct the cycle.'];
        }

        $requirements = $set->requirements->where('requires_preliminary_evidence', true)->sortBy('display_order');

        if ($this->isCorrectionMode()) {
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

    public function activeCorrectionRequest(): ?ApplicationCorrectionRequest
    {
        return $this->currentApplication()?->correctionRequests
            ->where('state', ApplicationCorrectionRequest::StateActive)
            ->sortByDesc('requested_at')
            ->first();
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
    protected function cycleOptions(): array
    {
        $availableCycleIds = AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->whereDoesntHave('applications', fn (Builder $applicationQuery): Builder => $applicationQuery
                ->whereNotNull('admission_cycle_id')
                ->whereNotNull('application_state')
                ->where('user_id', $this->applicationOwner()?->id))
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
    protected function programOptions(int $cycleId, string $path): array
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
            'privacy_acknowledged' => $application->privacy_acknowledged_at !== null
                && $application->privacy_notice_reference === $application->admissionCycle?->privacy_notice_reference,
            'optional_identity_consent' => $application->optional_identity_consented_at !== null,
            'gender' => $application->optional_identity_consented_at !== null ? $application->gender : null,
            'civil_status' => $application->optional_identity_consented_at !== null ? $application->civil_status : null,
            'accuracy_declared' => false,
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
        if ($this->isCorrectionMode()) {
            return $this->activeCorrectionRequest() !== null;
        }
        $cycleId = $this->currentApplication()?->admission_cycle_id ?? ($this->data['admission_cycle_id'] ?? null);
        if ($cycleId === null) {
            return $this->admissionsAreOpen();
        }
        $cycle = AdmissionCycle::query()->find($cycleId);

        return $cycle?->state === AdmissionCycle::StatePublished
            && $cycle->opens_at <= now()
            && $cycle->closes_at > now();
    }

    /** @return array<string, mixed> */
    protected function continuationUrlParameters(): array
    {
        return $this->sourceApplicationId !== null ? ['application' => $this->sourceApplicationId] : [];
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

        if ($application instanceof AdmissionApplication
            && $application->application_state === AdmissionApplication::StateActionNeeded) {
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

            if ($scopes->contains('scope_type', ApplicationCorrectionItem::ScopeEvidence)) {
                return 4;
            }

            $keys = $scopes->pluck('scope_key');

            if ($keys->intersect(['application_path', 'program_id'])->isNotEmpty()) {
                return 1;
            }

            if ($keys->intersect([
                'first_name', 'middle_name', 'last_name', 'extension_name', 'birth_date',
                'citizenship_country_code', 'phone', 'current_city_municipality', 'current_province',
                'guardian_full_name', 'guardian_relationship', 'guardian_mobile',
                'gender', 'civil_status', 'current_barangay', 'current_street_address', 'current_postal_code',
            ])->isNotEmpty()) {
                return 2;
            }

            if ($keys->intersect([
                'prior_school_name', 'prior_school_country_code', 'prior_school_completion_year',
                'credential_basis', 'lrn', 'lrn_availability', 'prior_college_identifier', 'prior_school_address',
            ])->isNotEmpty()) {
                return 3;
            }

            return 5;
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
            $application->prior_school_completion_year,
            $application->lrn_availability,
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
