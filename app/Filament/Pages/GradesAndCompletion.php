<?php

namespace App\Filament\Pages;

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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GradesAndCompletion extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Grades & Academic Records';

    protected static ?string $title = 'Grades & Academic Records';

    protected string $view = 'filament.pages.grades-and-completion';

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
                    Select::make('student_profile_id')->label('Student')->options(StudentProfile::query()->orderBy('last_name')->get()->mapWithKeys(
                        fn (StudentProfile $student): array => [$student->id => collect([$student->student_number, $student->last_name, $student->first_name])->filter()->implode(' · ')],
                    ))->required()->searchable(),
                    Select::make('requirement_id')->label('Authorized requirement')->options(ExternalCompetencyRequirement::query()->where('state', 'ACTIVE')->orderBy('requirement_code')->pluck('qualification_label', 'id'))->required()->searchable(),
                    Select::make('outcome')->options([
                        ExternalCompetencyResult::OutcomeNotYetCompetent => 'Not Yet Competent',
                        ExternalCompetencyResult::OutcomeCompetent => 'Competent',
                    ])->required(),
                    TextInput::make('evidence_reference')->required()->maxLength(255),
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

    /** @return list<array{title: string, description: string, action: string, url: string, icon: string}> */
    public function workAreas(): array
    {
        return [
            [
                'title' => 'Grade Review, INC, and Corrections',
                'description' => 'Review immutable Faculty submissions, return named rows, release complete rosters, and manage INC or correction successors.',
                'action' => 'Open official grade rosters',
                'url' => GradeRosterResource::getUrl('index'),
                'icon' => 'heroicon-o-document-check',
            ],
            [
                'title' => 'Student Academic Records',
                'description' => 'Review own-record projections, curriculum context, academic decisions, and authenticated unofficial-record access.',
                'action' => 'Open Student records',
                'url' => StudentProfileResource::getUrl('index'),
                'icon' => 'heroicon-o-identification',
            ],
            [
                'title' => 'Lifecycle History',
                'description' => 'Record and review leave, withdrawal, transfer, reactivation, and program-shift authority without completion or conferral claims.',
                'action' => 'Open lifecycle records',
                'url' => StudentLifecycleChangeResource::getUrl('index'),
                'icon' => 'heroicon-o-arrow-path',
            ],
            [
                'title' => 'Completion & TOR',
                'description' => 'Review attributable readiness, record immutable conferral, and manage request-bound TALA Standard TOR history.',
                'action' => 'Open Completion & TOR',
                'url' => CompletionAndTor::getUrl(),
                'icon' => 'heroicon-o-document-text',
            ],
        ];
    }
}
