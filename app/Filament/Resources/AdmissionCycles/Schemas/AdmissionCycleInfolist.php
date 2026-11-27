<?php

namespace App\Filament\Resources\AdmissionCycles\Schemas;

use App\Actions\Admissions\AdmissionCycleReadinessService;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermOverview;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionCycle;
use App\Models\Program;
use App\Models\Term;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class AdmissionCycleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Publication checks')
                    ->compact()
                    ->description(fn (AdmissionCycle $record): string => trans_choice(
                        ':count item to fix before this cycle can be published.|:count items to fix before this cycle can be published.',
                        count(app(AdmissionCycleReadinessService::class)->for($record)['blockers']),
                    ))
                    ->visible(fn (AdmissionCycle $record): bool => app(AdmissionCycleReadinessService::class)->for($record)['blockers'] !== [])
                    ->schema([
                        View::make('filament.admin.admission-cycles.publication-checks')
                            ->viewData(fn (AdmissionCycle $record): array => [
                                'findings' => collect(app(AdmissionCycleReadinessService::class)->for($record)['blockers'])
                                    ->map(fn (array $finding): array => [...$finding, 'fix' => self::fixLink($finding, $record)])
                                    ->all(),
                            ]),
                    ])
                    ->columnSpanFull(),
                Section::make('Intake')
                    ->icon('heroicon-o-user-plus')
                    ->compact()
                    ->afterHeader([
                        Text::make(fn (AdmissionCycle $record): string => self::intakeStatus($record)['label'])
                            ->badge()
                            ->color(fn (AdmissionCycle $record): string => self::intakeStatus($record)['color']),
                    ])
                    ->schema([
                        View::make('filament.admin.admission-cycles.overview-intake')
                            ->viewData(fn (AdmissionCycle $record): array => self::intakeViewData($record)),
                    ])
                    ->columnSpanFull(),
                Section::make('Application dates')
                    ->icon('heroicon-o-calendar-days')
                    ->compact()
                    ->schema([
                        View::make('filament.admin.admission-cycles.overview-dates')
                            ->viewData(fn (AdmissionCycle $record): array => self::datesViewData($record)),
                    ])
                    ->columnSpanFull(),
                Section::make('Applicant guidance and source details')
                    ->icon('heroicon-o-document-text')
                    ->compact()
                    ->schema([
                        View::make('filament.admin.admission-cycles.overview-guidance')
                            ->viewData(fn (AdmissionCycle $record): array => self::guidanceViewData($record)),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function guidanceViewData(AdmissionCycle $record): array
    {
        $blockers = app(AdmissionCycleReadinessService::class)->for($record)['blockers'];

        return [
            'code' => $record->code,
            'codeNote' => $record->state === AdmissionCycle::StateDraft ? 'Reserved once the cycle is published' : 'Reserved for this cycle',
            'supportContact' => $record->support_contact,
            'privacyReference' => $record->privacy_notice_reference,
            'checksPassed' => $blockers === [],
            'checksLabel' => trans_choice(':count item to fix|:count items to fix', count($blockers)),
            'instructions' => $record->applicant_instructions,
        ];
    }

    /**
     * Short header badge for the Intake card, with the longer guidance shown under it (#110).
     *
     * @return array{label: string, color: string, note: ?string}
     */
    public static function intakeStatus(AdmissionCycle $record): array
    {
        return match (true) {
            $record->state === AdmissionCycle::StateDraft => ['label' => 'Draft', 'color' => 'gray', 'note' => 'Finish setup and publish to open applications.'],
            $record->state === AdmissionCycle::StateCancelled => ['label' => 'Cancelled', 'color' => 'danger', 'note' => null],
            $record->state !== AdmissionCycle::StatePublished || ! $record->opens_at || ! $record->closes_at => ['label' => 'Dates needed', 'color' => 'warning', 'note' => 'Review the application window before applicants can apply.'],
            $record->opens_at->isFuture() => ['label' => 'Scheduled', 'color' => 'info', 'note' => null],
            $record->closes_at <= now() => ['label' => 'Closed', 'color' => 'gray', 'note' => 'New applications are closed; existing reviews continue.'],
            default => ['label' => 'Open', 'color' => 'success', 'note' => null],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function intakeViewData(AdmissionCycle $record): array
    {
        $record->loadMissing(['term.academicYear', 'programs', 'registrarOwner']);

        $programs = $record->programs->map(function (Program $program): array {
            $pivot = $program->getRelation('pivot');
            $types = collect([
                AdmissionCycle::PathFirstYear => (bool) data_get($pivot, 'accepts_first_year'),
                AdmissionCycle::PathTransferee => (bool) data_get($pivot, 'accepts_transferee'),
            ])->filter()->keys()->map(fn (string $path): array => [
                'label' => AdmissionCycle::studentTypeLabel($path),
                'color' => AdmissionCycle::studentTypeColor($path),
            ])->values()->all();

            return ['name' => $program->name, 'types' => $types];
        })->values()->all();

        $studentTypes = collect($programs)->flatMap(fn (array $program): array => array_column($program['types'], 'label'))->unique()->values()->all();

        return [
            'statusNote' => self::intakeStatus($record)['note'],
            'academicYear' => $record->term instanceof Term ? AdmissionCycleForm::academicYearOptionLabel($record->term) : null,
            'academicYearUrl' => $record->term_id !== null && TermOverview::canAccess() ? TermCalendar::getUrl(['termId' => $record->term_id]) : null,
            'studentTypes' => $studentTypes,
            'programs' => $programs,
            'registrar' => $record->registrarOwner?->email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function datesViewData(AdmissionCycle $record): array
    {
        $isDraft = $record->state === AdmissionCycle::StateDraft;
        $date = fn (string $label, mixed $value): array => [
            'label' => $label,
            'day' => $value?->timezone('Asia/Manila')->format('M j, Y'),
            'time' => $value?->timezone('Asia/Manila')->format('g:i A'),
            'required' => $isDraft,
        ];

        return ['groups' => [
            ['heading' => 'Application window', 'dates' => [
                $date('Applications open', $record->opens_at),
                $date('New applications close', $record->closes_at),
                $date('Correction request deadline', $record->correction_closes_at),
            ]],
            ['heading' => 'After admission', 'dates' => [
                $date('Physical papers due', $record->requirements_due_at),
                $date('Enrollment payment due', $record->payment_due_at),
                $date('Final admission cutoff', $record->period_ends_at),
            ]],
        ]];
    }

    /**
     * Where the Registrar resolves a publication blocker, when it is fixable from TALA's cycle setup.
     *
     * @param  array{code: string}  $finding
     * @return array{label: string, url: string}|null
     */
    public static function fixLink(array $finding, AdmissionCycle $record): ?array
    {
        if (str_starts_with($finding['code'], 'requirement_set_')) {
            return $record->state === AdmissionCycle::StateDraft
                ? ['label' => 'Set requirements', 'url' => AdmissionCycleResource::getUrl('edit', ['record' => $record, 'step' => 'form.programs-student-types::data::wizard-step'])]
                : ['label' => 'Open requirement sets', 'url' => AdmissionCycleResource::getUrl('view', ['record' => $record, 'relation' => '0'])];
        }

        $label = match ($finding['code']) {
            'cycle_code_reserved' => 'Change cycle code',
            'post_admission_deadlines' => 'Set post-admission deadlines',
            'target_term_and_dates' => 'Fix term and dates',
            'accepting_programs' => 'Choose accepting programs',
            'applicant_guidance' => 'Add applicant guidance',
            'registrar_owner' => 'Assign Registrar owner',
            default => null,
        };

        if ($label === null || $record->state !== AdmissionCycle::StateDraft || ! AdmissionCycleResource::canEdit($record)) {
            return null;
        }

        return ['label' => $label, 'url' => AdmissionCycleResource::getUrl('edit', ['record' => $record])];
    }
}
