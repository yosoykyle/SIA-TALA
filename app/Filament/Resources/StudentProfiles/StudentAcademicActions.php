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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class StudentAcademicActions
{
    /** @return list<Action> */
    public static function make(StudentProfile $student): array
    {
        $requirements = null;
        $qualifications = function () use ($student, &$requirements): Collection {
            return $requirements ??= ExternalCompetencyRequirement::query()->applicableTo($student)
                ->withCurrentResultFor($student)->orderBy('requirement_code')->get()->keyBy('id');
        };

        return [
            Action::make('recordExternalCompetency')
                ->label('Record external assessment result')->color('gray')
                ->visible(fn (): bool => auth()->user()?->status === User::StatusActive && auth()->user()->hasRole(User::StaffRoleRegistrar))
                ->modalDescription('Record a result already verified by the school. A reassessment keeps the earlier attempt in the student’s record.')
                ->modalSubmitActionLabel('Record result')
                ->modalWidth(Width::FourExtraLarge)
                ->stickyModalFooter()
                ->schema([
                    Hidden::make('command_key')->default(fn (): string => (string) Str::uuid()),
                    Hidden::make('expected_predecessor_id')->default(0)->required(),
                    Section::make('Assessment')
                        ->compact()
                        ->columns(2)
                        ->schema([
                            Select::make('requirement_id')->label('Qualification')->placeholder('Choose a qualification')
                                ->options(fn (): array => $qualifications()->pluck('qualification_label', 'id')->all())
                                ->helperText(function (Get $get) use ($qualifications): string {
                                    if ($qualifications()->isEmpty()) {
                                        return 'No active qualifications are listed. Check the student’s curriculum in Catalog & Curricula.';
                                    }

                                    $qualification = $qualifications()->get($get('requirement_id') ?? 0);

                                    if (! $qualification instanceof ExternalCompetencyRequirement) {
                                        return 'Choose an active qualification from the student’s curriculum.';
                                    }

                                    $result = $qualification->currentResult;
                                    $status = ExternalCompetencyResult::outcomeLabel($result?->outcome);

                                    return 'Current result: '.$status.($result?->assessment_date ? ' · '.$result->assessment_date->format('M j, Y') : '');
                                })
                                ->noSearchResultsMessage('No active requirements match. Check this student’s curriculum setup.')
                                ->required()->searchable()->live()->columnSpanFull()
                                ->validationMessages(['in' => 'Choose a current qualification. If the curriculum changed, reopen the action.'])
                                ->afterStateUpdated(function (Get $get, Set $set) use ($qualifications): void {
                                    $set('expected_predecessor_id', $qualifications()->get($get('requirement_id') ?? 0)?->currentResult?->id ?? 0);
                                }),
                            Select::make('outcome')->label('Verified result')->options([
                                ExternalCompetencyResult::OutcomeNotYetCompetent => 'Not yet competent',
                                ExternalCompetencyResult::OutcomeCompetent => 'Competent',
                            ])->required(),
                            DatePicker::make('assessment_date')->label('Assessment date')->required(),
                        ]),
                    Section::make('Verified evidence')
                        ->compact()
                        ->description('Use the assessment provider’s record and the school’s approval to document this result.')
                        ->columns(2)
                        ->schema([
                            TextInput::make('external_source')->label('Assessment provider')->required()->maxLength(255),
                            TextInput::make('evidence_reference')->label('Private evidence reference')
                                ->helperText('Enter the identifier of the verified assessment record.')->required()->maxLength(255),
                            TextInput::make('authority_reference')->label('School approval reference')->required()->maxLength(255),
                            DatePicker::make('authority_date')->label('School approval date')->required(),
                        ]),
                    Section::make('Credential and notes (optional)')
                        ->compact()->collapsible()->collapsed()->columns(2)
                        ->schema([
                            Select::make('credential_type')->label('Credential type')->options(['NC' => 'NC', 'COC' => 'COC'])
                                ->requiredWith(['credential_reference', 'credential_valid_until']),
                            TextInput::make('credential_reference')->label('Credential reference')->requiredWith('credential_type')->maxLength(255),
                            DatePicker::make('credential_valid_until')->label('Credential valid until'),
                            Textarea::make('safe_remarks')->label('Remarks')
                                ->helperText('Keep sensitive details in the private evidence record.')
                                ->rows(2)->maxLength(1000)->columnSpanFull(),
                        ]),
                ])
                ->action(function (array $data) use ($student): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    try {
                        $requirement = ExternalCompetencyRequirement::query()->find((int) $data['requirement_id'])
                            ?? throw new RuntimeException('This qualification is no longer available. Reopen the action and select a current qualification.');

                        app(RecordExternalCompetencyResult::class)->execute(
                            $requirement,
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
