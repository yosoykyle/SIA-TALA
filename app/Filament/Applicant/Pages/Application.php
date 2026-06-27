<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Applicants\ApplicantIntakeService;
use App\Models\ApplicantIntake;
use App\Models\Program;
use App\Models\Term;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use LogicException;

class Application extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'My Application';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.applicant.pages.application';

    /**
     * @var array<string, mixed> | null
     */
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

        $this->applicationForm()->fill([
            ...($intake?->only($this->formAttributes()) ?? []),
            'term_id' => $intake instanceof ApplicantIntake
                ? $intake->term_id
                : Term::query()->where('is_active', true)->value('id'),
            'orientation_modality_acknowledged' => $intake?->orientation_modality_acknowledged_at !== null,
            'orientation_policy_accepted' => $intake?->orientation_policy_accepted_at !== null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application Scope')
                    ->description('Select the active term and College program you are applying for.')
                    ->schema([
                        Select::make('term_id')
                            ->label('Admission Term')
                            ->options(fn (): array => Term::query()
                                ->where('is_active', true)
                                ->orderByDesc('id')
                                ->pluck('term_name', 'id')
                                ->all())
                            ->helperText('Required before submission.'),
                        Select::make('program_id')
                            ->label('Preferred Program')
                            ->options(fn (): array => Program::query()
                                ->where('is_active', true)
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->helperText('Required before submission.'),
                        Select::make('applicant_type')
                            ->options([
                                ApplicantIntake::ApplicantTypeNew => 'First-Time College Applicant',
                                ApplicantIntake::ApplicantTypeTransferee => 'Transfer Applicant',
                                ApplicantIntake::ApplicantTypeReturnee => 'Returning Student / Readmission',
                            ])
                            ->live(),
                        Select::make('year_level')
                            ->options([
                                '1st Year' => '1st Year',
                                '2nd Year' => '2nd Year',
                                '3rd Year' => '3rd Year',
                                '4th Year' => '4th Year',
                            ]),
                        Select::make('preferred_modality')
                            ->label('Preferred Delivery Modality')
                            ->options([
                                'on_site' => 'On Site',
                                'blended' => 'Blended',
                                'online' => 'Online',
                            ]),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Identity and Contact')
                    ->schema([
                        TextInput::make('lrn')
                            ->label('Learner Reference Number (LRN)')
                            ->length(12)
                            ->numeric(),
                        DatePicker::make('birthdate')
                            ->maxDate(now()->subDay())
                            ->native(false),
                        TextInput::make('place_of_birth')->maxLength(255),
                        Select::make('gender')->options([
                            'female' => 'Female',
                            'male' => 'Male',
                        ]),
                        Select::make('civil_status')->options([
                            'single' => 'Single',
                            'married' => 'Married',
                            'widowed' => 'Widowed',
                            'separated' => 'Separated',
                            'annulled' => 'Annulled',
                        ]),
                        TextInput::make('contact_number')
                            ->tel()
                            ->placeholder('09XXXXXXXXX')
                            ->maxLength(11),
                        TextInput::make('mothers_maiden_name')->maxLength(255),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Current Address')
                    ->schema([
                        TextInput::make('street')->maxLength(255),
                        TextInput::make('barangay')->maxLength(255),
                        TextInput::make('city')->maxLength(255),
                        TextInput::make('province')->maxLength(255),
                        TextInput::make('region')->maxLength(255),
                        TextInput::make('zip_code')->maxLength(20),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Guardian and Prior School')
                    ->schema([
                        TextInput::make('guardian_name')->maxLength(255),
                        TextInput::make('guardian_contact_number')
                            ->tel()
                            ->maxLength(11),
                        TextInput::make('guardian_address')->maxLength(255),
                        TextInput::make('last_school_name')
                            ->visible(fn (Get $get): bool => $get('applicant_type') === ApplicantIntake::ApplicantTypeTransferee)
                            ->maxLength(255),
                        TextInput::make('last_school_address')
                            ->visible(fn (Get $get): bool => $get('applicant_type') === ApplicantIntake::ApplicantTypeTransferee)
                            ->maxLength(255),
                        TextInput::make('last_school_year')->maxLength(80),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Required Identity Evidence')
                    ->description('Upload exactly one identity document for Registrar verification. Files remain private.')
                    ->schema([
                        FileUpload::make('identity_document_url')
                            ->label('Identity Document')
                            ->disk('local')
                            ->directory(fn (): string => 'applicant-identity-documents/'.Auth::id())
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxSize(5120)
                            ->helperText('PDF, JPG, or PNG; maximum 5 MB.'),
                        Checkbox::make('orientation_modality_acknowledged')
                            ->label('I understand that my selected modality is a preference until officially confirmed.'),
                        Checkbox::make('orientation_policy_accepted')
                            ->label('I confirm that the information and identity evidence I submit are accurate.'),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function saveDraft(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant !== null, 403);

        app(ApplicantIntakeService::class)->saveDraft($applicant, $this->applicationForm()->getState());

        Notification::make()
            ->title('Application draft saved')
            ->success()
            ->send();
    }

    public function submitApplication(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant !== null, 403);

        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $this->applicationForm()->getState());
        app(ApplicantIntakeService::class)->submit($draft);

        Notification::make()
            ->title('Application submitted for Registrar review')
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl());
    }

    /**
     * @return list<string>
     */
    private function formAttributes(): array
    {
        return [
            'term_id',
            'program_id',
            'lrn',
            'birthdate',
            'place_of_birth',
            'gender',
            'civil_status',
            'mothers_maiden_name',
            'contact_number',
            'street',
            'barangay',
            'city',
            'province',
            'region',
            'zip_code',
            'guardian_name',
            'guardian_contact_number',
            'guardian_address',
            'year_level',
            'applicant_type',
            'preferred_modality',
            'last_school_name',
            'last_school_address',
            'last_school_year',
            'identity_document_url',
        ];
    }

    private function applicationForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Applicant application form schema is unavailable.');
    }
}
