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
                Section::make('Application Scope')
                    ->description('Select the active term, program, admission category, and credential basis.')
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
                            ->required(),
                        Select::make('credential_basis')
                            ->options([
                                ApplicantIntake::CredentialBasisSeniorHighSchool => 'Senior High School Credential',
                                ApplicantIntake::CredentialBasisTransferCredentials => 'Transfer Credentials',
                                ApplicantIntake::CredentialBasisPriorStudentRecord => 'Prior Student Record',
                            ])
                            ->required(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Identity and Contact')
                    ->schema([
                        TextInput::make('first_name')->maxLength(255),
                        TextInput::make('middle_name')->maxLength(255),
                        TextInput::make('last_name')->maxLength(255),
                        DatePicker::make('birth_date')->maxDate(now()->subDay())->native(false),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('phone')->tel()->placeholder('09XXXXXXXXX')->maxLength(11),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Prior School')
                    ->schema([
                        TextInput::make('prior_school')->maxLength(255),
                    ])
                    ->columnSpanFull(),
                Section::make('Required Identity Evidence')
                    ->description('Upload exactly one identity document for Registrar verification. Files remain private.')
                    ->schema([
                        FileUpload::make('identity_evidence_reference')
                            ->label('Identity Document')
                            ->disk('local')
                            ->directory(fn (): string => 'applicant-identity-documents/'.Auth::id())
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                            ->maxSize(5120)
                            ->helperText('PDF, JPG, or PNG; maximum 5 MB.'),
                        Checkbox::make('information_confirmed')
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

        Notification::make()->title('Application draft saved')->success()->send();
    }

    public function submitApplication(): void
    {
        $applicant = Auth::user();
        abort_unless($applicant !== null, 403);

        $draft = app(ApplicantIntakeService::class)->saveDraft($applicant, $this->applicationForm()->getState());
        app(ApplicantIntakeService::class)->submit($draft);

        Notification::make()->title('Application submitted for Registrar review')->success()->send();
        $this->redirect(Dashboard::getUrl());
    }

    /** @return list<string> */
    private function formAttributes(): array
    {
        return [
            'term_id', 'program_id', 'admission_category', 'credential_basis',
            'first_name', 'middle_name', 'last_name', 'birth_date', 'email',
            'phone', 'prior_school', 'identity_evidence_reference',
        ];
    }

    private function applicationForm(): Schema
    {
        return $this->getSchema('form') ?? throw new LogicException('Applicant application form schema is unavailable.');
    }
}
