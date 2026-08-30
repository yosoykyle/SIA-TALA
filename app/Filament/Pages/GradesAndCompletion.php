<?php

namespace App\Filament\Pages;

use App\Actions\Academics\ExaminationPeriodProjection;
use App\Actions\Academics\RecordAcademicDecision;
use App\Actions\Academics\RecordExternalCompetencyResult;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Models\AcademicDecision;
use App\Models\ExternalCompetencyRequirement;
use App\Models\ExternalCompetencyResult;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAverageLabel;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

class GradesAndCompletion extends Page
{
    /** @var list<string> */
    private const Tabs = ['grade-review', 'inc-corrections', 'academic-progress', 'lifecycle', 'completion-tor', 'history'];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Grades & Academic Records';

    protected static ?string $title = 'Grades & Academic Records';

    protected string $view = 'filament.pages.grades-and-completion';

    #[Url]
    public string $viewTab = 'grade-review';

    public function mount(): void
    {
        $this->viewTab = in_array($this->viewTab, self::Tabs, true) ? $this->viewTab : 'grade-review';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordExternalCompetency')
                ->label('Record External Competency')
                ->schema([
                    Hidden::make('command_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('student_profile_id')->label('Student')->options(StudentProfile::query()->orderBy('last_name')->get()->mapWithKeys(
                        fn (StudentProfile $student): array => [$student->id => collect([$student->student_number, $student->last_name, $student->first_name])->filter()->implode(' · ')],
                    ))->required()->searchable(),
                    Select::make('requirement_id')->label('Authorized requirement')->options(ExternalCompetencyRequirement::query()->where('state', 'ACTIVE')->orderBy('requirement_code')->pluck('qualification_label', 'id'))->required()->searchable(),
                    Select::make('outcome')->options([
                        ExternalCompetencyResult::OutcomeNotYetCompetent => 'Not Yet Competent',
                        ExternalCompetencyResult::OutcomeCompetent => 'Competent',
                    ])->required(),
                    DatePicker::make('assessment_date')->label('Assessment date')->required(),
                    TextInput::make('external_source')->label('External evidence source')->required()->maxLength(255),
                    TextInput::make('evidence_reference')->label('Private evidence reference')->required()->maxLength(255),
                    Select::make('credential_type')->label('Credential type (optional)')->options(['NC' => 'NC', 'COC' => 'COC']),
                    TextInput::make('credential_reference')->label('Credential reference')->requiredWith('credential_type')->maxLength(255),
                    DatePicker::make('credential_valid_until')->label('Credential valid until'),
                    Textarea::make('safe_remarks')->label('Safe remarks')->helperText('Do not enter sensitive assessment evidence.')->maxLength(1000),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    DatePicker::make('authority_date')->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(RecordExternalCompetencyResult::class)->execute(
                        ExternalCompetencyRequirement::query()->findOrFail((int) $data['requirement_id']),
                        StudentProfile::query()->findOrFail((int) $data['student_profile_id']),
                        (string) $data['outcome'],
                        (string) $data['evidence_reference'],
                        (string) $data['authority_reference'],
                        Carbon::parse($data['authority_date']),
                        $actor,
                        assessmentDate: Carbon::parse($data['assessment_date']),
                        externalSource: (string) $data['external_source'],
                        credentialType: filled($data['credential_type'] ?? null) ? (string) $data['credential_type'] : null,
                        credentialReference: filled($data['credential_reference'] ?? null) ? (string) $data['credential_reference'] : null,
                        credentialValidUntil: filled($data['credential_valid_until'] ?? null) ? Carbon::parse($data['credential_valid_until']) : null,
                        safeRemarks: filled($data['safe_remarks'] ?? null) ? (string) $data['safe_remarks'] : null,
                        commandKey: (string) $data['command_key'],
                    );
                    Notification::make()->title('External competency result recorded')->success()->send();
                }),
            Action::make('recordAcademicDecision')
                ->label('Record Academic Decision')
                ->schema([
                    Select::make('student_profile_id')->label('Student')->options(StudentProfile::query()->orderBy('last_name')->get()->mapWithKeys(
                        fn (StudentProfile $student): array => [$student->id => collect([$student->student_number, $student->last_name, $student->first_name])->filter()->implode(' · ')],
                    ))->required()->searchable(),
                    Select::make('term_id')->label('Term')->options(Term::query()->latest('starts_on')->pluck('label', 'id'))->searchable(),
                    Select::make('effect')->options(AcademicDecision::effectOptions())->required(),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    DatePicker::make('authority_date')->required(),
                    Textarea::make('reason')->required()->maxLength(2000),
                    DatePicker::make('effective_from')->required(),
                    DatePicker::make('effective_until')->afterOrEqual('effective_from'),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(RecordAcademicDecision::class)->execute(
                        StudentProfile::query()->findOrFail((int) $data['student_profile_id']),
                        filled($data['term_id'] ?? null) ? Term::query()->findOrFail((int) $data['term_id']) : null,
                        (string) $data['effect'],
                        (string) $data['authority_reference'],
                        Carbon::parse($data['authority_date']),
                        (string) $data['reason'],
                        Carbon::parse($data['effective_from']),
                        filled($data['effective_until'] ?? null) ? Carbon::parse($data['effective_until']) : null,
                        $actor,
                    );
                    Notification::make()->title('Academic decision recorded')->success()->send();
                }),
            Action::make('recordTermAverageLabel')
                ->label('Record Term Average Label')
                ->schema([
                    Select::make('term_id')->label('Term')->options(Term::query()->latest('starts_on')->pluck('label', 'id'))->required()->searchable(),
                    TextInput::make('label')->helperText('Optional institution-approved display wording. The neutral default is Term weighted average.')->required()->maxLength(100),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    DatePicker::make('authority_date')->required(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    DB::transaction(function () use ($data, $actor): void {
                        TermAverageLabel::query()->where('term_id', (int) $data['term_id'])->where('is_current', true)->lockForUpdate()->update(['is_current' => false]);
                        TermAverageLabel::query()->create([...$data, 'recorded_by' => $actor->id, 'recorded_at' => now(), 'is_current' => true]);
                    }, 3);
                    Notification::make()->title('Term average label recorded')->success()->send();
                }),
        ];
    }

    /** @return array<string, string> */
    public function tabs(): array
    {
        return [
            'grade-review' => 'Grade Review',
            'inc-corrections' => 'INC & Corrections',
            'academic-progress' => 'Academic Progress',
            'lifecycle' => 'Lifecycle',
            'completion-tor' => 'Completion & TOR',
            'history' => 'History',
        ];
    }

    public function showTab(string $tab): void
    {
        if (in_array($tab, self::Tabs, true)) {
            $this->viewTab = $tab;
        }
    }

    /** @return array{title: string, description: string, action: string, url: string, icon: string} */
    public function activeWorkArea(): array
    {
        return $this->workAreas()[$this->viewTab];
    }

    /** @return array<string, mixed> */
    public function examinationPeriod(): array
    {
        return app(ExaminationPeriodProjection::class)->latest();
    }

    /** @return array<string, array{title: string, description: string, action: string, url: string, icon: string}> */
    public function workAreas(): array
    {
        return [
            'grade-review' => [
                'title' => 'Grade Review',
                'description' => 'Review immutable Faculty submissions, return named rows with one consolidated explanation, or release the complete current version.',
                'action' => 'Open official grade rosters',
                'url' => GradeRosterResource::getUrl('index'),
                'icon' => 'heroicon-o-document-check',
            ],
            'inc-corrections' => [
                'title' => 'INC & Corrections',
                'description' => 'Review open or overdue INC work, deadline authority, immutable completion successors, and authorized grade corrections.',
                'action' => 'Open released result rows',
                'url' => GradeRosterResource::getUrl('index'),
                'icon' => 'heroicon-o-arrow-path-rounded-square',
            ],
            'academic-progress' => [
                'title' => 'Academic Progress',
                'description' => 'Review released-only results, source-labelled averages, curriculum context, external competency, and academic decisions.',
                'action' => 'Open Student records',
                'url' => StudentProfileResource::getUrl('index'),
                'icon' => 'heroicon-o-identification',
            ],
            'lifecycle' => [
                'title' => 'Lifecycle History',
                'description' => 'Record and review leave, full withdrawal, transfer, reactivation, and program-shift authority with Accounting review only where applicable.',
                'action' => 'Open lifecycle records',
                'url' => StudentLifecycleChangeResource::getUrl('index'),
                'icon' => 'heroicon-o-arrow-path',
            ],
            'completion-tor' => [
                'title' => 'Completion & TOR',
                'description' => 'Preserved Slice 6 destination for completion, conferral, and TALA Standard TOR work. It is not counted as Slice 5 completion.',
                'action' => 'Open Completion & TOR',
                'url' => CompletionAndTor::getUrl(),
                'icon' => 'heroicon-o-document-text',
            ],
            'history' => [
                'title' => 'History',
                'description' => 'Inspect attributable roster versions, returned rows, releases, INC successors, corrections, and lifecycle evidence without rewriting prior records.',
                'action' => 'Open academic record history',
                'url' => GradeRosterResource::getUrl('index'),
                'icon' => 'heroicon-o-clock',
            ],
        ];
    }
}
