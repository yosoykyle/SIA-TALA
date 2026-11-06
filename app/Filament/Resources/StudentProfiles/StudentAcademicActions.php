<?php

namespace App\Filament\Resources\StudentProfiles;

use App\Actions\Academics\RecordAcademicDecision;
use App\Actions\Academics\RecordExternalCompetencyResult;
use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Models\AcademicDecision;
use App\Models\ExternalCompetencyRequirement;
use App\Models\ExternalCompetencyResult;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class StudentAcademicActions
{
    /** @return list<Action> */
    public static function make(StudentProfile $student): array
    {
        return [
            Action::make('recordExternalCompetency')
                ->label('Record external competency')->color('gray')
                ->visible(fn (): bool => auth()->user()?->status === User::StatusActive && auth()->user()->hasRole(User::StaffRoleRegistrar))
                ->modalDescription('Record a verified result from an external assessment. Registrar and recording time are captured automatically.')
                ->modalSubmitActionLabel('Record result')
                ->modalWidth(Width::FourExtraLarge)
                ->stickyModalFooter()
                ->schema([
                    Hidden::make('command_key')->default(fn (): string => (string) Str::uuid()),
                    Hidden::make('expected_predecessor_id')->default(0)->required(),
                    Section::make('Assessment')
                        ->columns(2)
                        ->schema([
                            Select::make('requirement_id')->label('Authorized requirement')
                                ->options(fn (): array => ExternalCompetencyRequirement::query()->where('state', 'ACTIVE')
                                    ->where('curriculum_version_id', $student->curriculum_version_id)
                                    ->whereHas('curriculumVersion', fn (Builder $versions): Builder => $versions->where('state', 'ACTIVE'))
                                    ->orderBy('requirement_code')->pluck('qualification_label', 'id')->all())
                                ->helperText(fn (Select $component): string => $component->getOptions() === []
                                        ? 'No active requirement is recorded for this curriculum. Check Catalog & Curricula.'
                                        : 'Only active requirements from this student’s curriculum are listed.')
                                ->noSearchResultsMessage('No active requirements match. Check this student’s curriculum setup.')
                                ->required()->searchable()->live()
                                ->afterStateUpdated(function (Get $get, Set $set) use ($student): void {
                                    $set('expected_predecessor_id', ExternalCompetencyResult::query()
                                        ->where('student_profile_id', $student->id)
                                        ->where('external_competency_requirement_id', $get('requirement_id'))
                                        ->where('is_current', true)->value('id') ?? 0);
                                }),
                            Select::make('outcome')->options([
                                ExternalCompetencyResult::OutcomeNotYetCompetent => 'Not Yet Competent',
                                ExternalCompetencyResult::OutcomeCompetent => 'Competent',
                            ])->required(),
                            DatePicker::make('assessment_date')->label('Assessment date')->required(),
                        ]),
                    Section::make('Evidence and approval')
                        ->columns(2)
                        ->schema([
                            TextInput::make('external_source')->label('Assessment source')->required()->maxLength(255),
                            TextInput::make('evidence_reference')->label('Private evidence reference')->required()->maxLength(255),
                            TextInput::make('authority_reference')->required()->maxLength(255),
                            DatePicker::make('authority_date')->required(),
                            Textarea::make('safe_remarks')->label('Safe remarks (optional)')
                                ->helperText('Keep sensitive assessment evidence in the private source record.')
                                ->rows(2)->maxLength(1000)->columnSpanFull(),
                        ]),
                    Section::make('Credential details (optional)')
                        ->collapsible()->collapsed()->columns(2)
                        ->schema([
                            Select::make('credential_type')->label('Credential type')->options(['NC' => 'NC', 'COC' => 'COC'])
                                ->requiredWith(['credential_reference', 'credential_valid_until']),
                            TextInput::make('credential_reference')->label('Credential reference')->requiredWith('credential_type')->maxLength(255),
                            DatePicker::make('credential_valid_until')->label('Credential valid until'),
                        ]),
                ])
                ->action(function (array $data) use ($student): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        app(RecordExternalCompetencyResult::class)->execute(
                            ExternalCompetencyRequirement::query()->findOrFail((int) $data['requirement_id']),
                            $student,
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
                            expectedPredecessorId: (int) $data['expected_predecessor_id'],
                            commandKey: (string) $data['command_key'],
                        );
                    } catch (RuntimeException $exception) {
                        Notification::make()->title('Result was not recorded')->body($exception->getMessage())->danger()->send();

                        throw new Halt;
                    }
                    Notification::make()->title('External competency result recorded')->success()->send();
                }),
            self::recordAcademicDecision($student),
        ];
    }

    public static function recordAcademicDecision(StudentProfile $student): Action
    {
        $termsQuery = Term::query()->with('academicYear')->latest('starts_on')->get();
        $termOptions = [
            'all' => 'All terms (student-wide)',
        ];
        foreach ($termsQuery as $term) {
            $termOptions[(string) $term->id] = collect([$term->academicYear?->label, $term->label])->filter()->implode(' · ');
        }

        $resolver = app(EnrollmentAcademicContextResolver::class);
        $currentEnrollment = $resolver->currentEnrollmentForProfile($student)
            ?? $student->enrollments()->latest('id')->first();
        $defaultTermScope = $currentEnrollment?->term_id ? (string) $currentEnrollment->term_id : null;

        return Action::make('recordAcademicDecision')
            ->label('Record enrollment decision')
            ->color('primary')
            ->visible(fn (): bool => auth()->user()?->status === User::StatusActive && auth()->user()->hasRole(User::StaffRoleRegistrar))
            ->modalHeading('Record enrollment decision')
            ->modalDescription('Record a decision already approved by the school.')
            ->modalSubmitActionLabel('Record decision')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['class' => 'tala-enrollment-decision-modal'])
            ->stickyModalFooter()
            ->modalFooterActions(function (Action $action): array {
                $actor = auth()->user();
                $actorName = $actor?->name ?? 'Registrar';

                return [
                    Action::make('footerAttribution')
                        ->view('filament.admin.student-profiles.decision-modal-footer-attribution', [
                            'actorName' => $actorName,
                        ]),
                    $action->getModalCancelAction(),
                    $action->getModalSubmitAction()->color('primary'),
                ];
            })
            ->schema([
                Grid::make(['default' => 1, 'lg' => 10])
                    ->schema([
                        Group::make([
                            SchemaView::make('filament.admin.student-profiles.decision-modal-context')
                                ->viewData(fn (Get $get): array => [
                                    'student' => $student,
                                    'effect' => $get('effect'),
                                    'termScope' => $get('term_id'),
                                    'startsOn' => $get('effective_from'),
                                    'endsOn' => $get('effective_until'),
                                    'termLabels' => $termOptions,
                                ]),
                        ])
                            ->extraAttributes(['class' => 'tala-decision-context-col'])
                            ->columnSpan(['default' => 1, 'lg' => 3]),

                        Group::make([
                            Grid::make(['default' => 1, 'sm' => 2])
                                ->schema([
                                    Select::make('effect')
                                        ->label('Enrollment effect')
                                        ->options(AcademicDecision::effectOptions())
                                        ->required()
                                        ->live(),

                                    Select::make('term_id')
                                        ->label('Applies to')
                                        ->options($termOptions)
                                        ->placeholder('Select term or student-wide')
                                        ->default($defaultTermScope)
                                        ->required()
                                        ->live(),

                                    Textarea::make('reason')
                                        ->label('Reason shown to student')
                                        ->helperText('Use a clear explanation without sensitive personal details.')
                                        ->required()
                                        ->rows(4)
                                        ->maxLength(2000)
                                        ->columnSpanFull(),

                                    DatePicker::make('effective_from')
                                        ->label('Starts on')
                                        ->default(fn (): string => today('Asia/Manila')->toDateString())
                                        ->required()
                                        ->live(),

                                    DatePicker::make('effective_until')
                                        ->label('Ends on (optional)')
                                        ->afterOrEqual('effective_from')
                                        ->live(),

                                    TextInput::make('authority_reference')
                                        ->label('Approval reference (optional)')
                                        ->placeholder('Memo or email reference, if issued')
                                        ->maxLength(255),

                                    DatePicker::make('authority_date')
                                        ->label('Approval date (optional)')
                                        ->placeholder('Choose date'),
                                ]),
                        ])
                            ->extraAttributes(['class' => 'tala-decision-form-col'])
                            ->columnSpan(['default' => 1, 'lg' => 7]),
                    ]),
            ])
            ->action(function (array $data) use ($student): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $termSelection = $data['term_id'] ?? null;
                if (! filled($termSelection)) {
                    throw new RuntimeException('Select an enrollment term or choose all terms.');
                }

                $term = $termSelection === 'all'
                    ? null
                    : Term::query()->findOrFail((int) $termSelection);

                $effectiveFrom = Carbon::parse($data['effective_from']);
                $effectiveUntil = filled($data['effective_until'] ?? null)
                    ? Carbon::parse($data['effective_until'])
                    : null;

                $authorityReference = filled($data['authority_reference'] ?? null)
                    ? trim((string) $data['authority_reference'])
                    : null;

                $authorityDate = filled($data['authority_date'] ?? null)
                    ? Carbon::parse($data['authority_date'])
                    : null;

                try {
                    app(RecordAcademicDecision::class)->execute(
                        $student,
                        $term,
                        (string) $data['effect'],
                        $authorityReference,
                        $authorityDate,
                        (string) $data['reason'],
                        $effectiveFrom,
                        $effectiveUntil,
                        $actor,
                    );

                    Notification::make()->title('Academic decision recorded')->success()->send();
                } catch (RuntimeException $exception) {
                    Notification::make()->title('Decision was not recorded')->body($exception->getMessage())->danger()->send();

                    throw new Halt;
                }
            });
    }
}
