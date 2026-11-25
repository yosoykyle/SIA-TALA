<?php

namespace App\Filament\Resources\StudentProfiles;

use App\Actions\StudentProfiles\OnboardExistingStudent;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

final class StudentOnboardingActions
{
    /**
     * Build the Registrar-only header actions for onboarding ongoing students
     * (`Import spreadsheet` for bulk upload and `Add student manually` for 1-by-1 entry).
     *
     * @param  Closure(): bool  $isVisible
     * @return list<Action>
     */
    public static function make(Closure $isVisible): array
    {
        return [
            self::importStudentsAction($isVisible),
            self::addExistingStudentAction($isVisible),
        ];
    }

    /**
     * @param  Closure(): bool  $isVisible
     */
    public static function importStudentsAction(Closure $isVisible): Action
    {
        return Action::make('importStudents')
            ->label('Import spreadsheet')
            ->color('primary')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible($isVisible)
            ->authorize($isVisible)
            ->url(fn (): string => StudentProfileResource::getUrl('import'));
    }

    /**
     * @param  Closure(): bool  $isVisible
     */
    public static function addExistingStudentAction(Closure $isVisible): Action
    {
        return Action::make('addExistingStudent')
            ->label('Add student manually')
            ->color('gray')
            ->icon(Heroicon::OutlinedUserPlus)
            ->visible($isVisible)
            ->authorize($isVisible)
            ->modalHeading('Add student manually')
            ->modalDescription('Onboard a single existing Servitech student into TALA without a spreadsheet, with optional prior completed subjects and passwordless account activation.')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Onboard student')
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('student_number')
                        ->label('Student Number')
                        ->placeholder('Leave blank to auto-allocate')
                        ->maxLength(64)
                        ->helperText('Optional. Leave blank to auto-allocate the next institutional Student Number.'),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('first_name')
                        ->label('First Name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('middle_name')
                        ->label('Middle Name')
                        ->maxLength(255),
                    TextInput::make('last_name')
                        ->label('Last Name')
                        ->required()
                        ->maxLength(255),
                    Select::make('academic_standing')
                        ->label('Academic Standing')
                        ->options([
                            StudentProfile::StandingRegular => 'Regular',
                            StudentProfile::StandingIrregular => 'Irregular',
                        ])
                        ->default(StudentProfile::StandingRegular)
                        ->required(),
                    Select::make('program_id')
                        ->label('Program')
                        ->required()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => Program::query()
                            ->where('is_active', true)
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (Program $program): array => [$program->id => StudentProfileResource::academicProgramLabel($program)])
                            ->all())
                        ->live()
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $set('prior_curriculum_entry_ids', []);
                            if (blank($state)) {
                                $set('curriculum_version_id', null);

                                return;
                            }

                            $active = CurriculumVersion::query()
                                ->where('program_id', (int) $state)
                                ->where('state', CurriculumVersion::StateActive)
                                ->orderByDesc('id')
                                ->get();

                            $set('curriculum_version_id', $active->count() === 1 ? (int) $active->sole()->id : null);
                        }),
                    Select::make('curriculum_version_id')
                        ->label('Curriculum Version')
                        ->required()
                        ->searchable()
                        ->live()
                        ->disabled(fn (Get $get): bool => blank($get('program_id')))
                        ->options(function (Get $get): array {
                            $programId = $get('program_id');
                            if (blank($programId)) {
                                return [];
                            }

                            return CurriculumVersion::query()
                                ->where('program_id', (int) $programId)
                                ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                                ->orderByRaw("CASE WHEN state = 'ACTIVE' THEN 0 ELSE 1 END")
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (CurriculumVersion $cv): array => [
                                    $cv->id => collect([
                                        $cv->version_code,
                                        $cv->name,
                                        $cv->state === CurriculumVersion::StateSuperseded ? '(Superseded)' : null,
                                    ])->filter()->implode(' · '),
                                ])
                                ->all();
                        })
                        ->afterStateUpdated(fn (Set $set) => $set('prior_curriculum_entry_ids', [])),
                    Select::make('entry_term_id')
                        ->label('Entry Term')
                        ->placeholder('Optional')
                        ->searchable()
                        ->options(fn (): array => Term::query()
                            ->with('academicYear')
                            ->orderByDesc('starts_on')
                            ->get()
                            ->mapWithKeys(fn (Term $term): array => [
                                $term->id => collect([$term->academicYear?->label, $term->label])->filter()->implode(' · '),
                            ])
                            ->all()),
                    TextInput::make('financial_hold_note')
                        ->label('Prior Financial Hold Note')
                        ->placeholder('Optional prior balance note')
                        ->maxLength(1000)
                        ->helperText('If provided, creates an active Accounting enrollment hold.'),
                ]),
                Select::make('prior_curriculum_entry_ids')
                    ->label('Prior Completed Subjects')
                    ->multiple()
                    ->searchable()
                    ->disabled(fn (Get $get): bool => blank($get('curriculum_version_id')))
                    ->options(function (Get $get): array {
                        $curriculumId = $get('curriculum_version_id');
                        if (blank($curriculumId)) {
                            return [];
                        }

                        return CurriculumEntry::query()
                            ->where('curriculum_version_id', (int) $curriculumId)
                            ->with('courseSpecification.course')
                            ->orderBy('year_level')
                            ->orderBy('sequence')
                            ->orderBy('id')
                            ->get()
                            ->mapWithKeys(function (CurriculumEntry $entry): array {
                                $code = $entry->courseSpecification?->course?->code;
                                $title = $entry->courseSpecification?->title;
                                $period = collect([
                                    filled($entry->year_level) ? 'Year '.$entry->year_level : null,
                                    $entry->term_label,
                                ])->filter()->implode(' · ');

                                $label = collect([$code, $title])->filter()->implode(' · ')
                                    .($period !== '' ? " ({$period})" : '');

                                return [$entry->id => $label];
                            })
                            ->all();
                    })
                    ->helperText('Optional. Selected subjects are recorded as prior institutional credits.'),
                Toggle::make('send_activation_email')
                    ->label('Send activation email now')
                    ->default(true)
                    ->helperText('Sends a passwordless account setup link to the student email immediately after onboarding.'),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $result = app(OnboardExistingStudent::class)->execute($data, $actor);
                $profile = $result['profile'];
                $activationNote = $result['activation_sent']
                    ? 'Activation email sent to '.$profile->email.'.'
                    : 'Profile created without sending an activation email.';

                Notification::make()
                    ->title('Student onboarded: '.$profile->student_number)
                    ->body($activationNote)
                    ->success()
                    ->send();
            });
    }
}
