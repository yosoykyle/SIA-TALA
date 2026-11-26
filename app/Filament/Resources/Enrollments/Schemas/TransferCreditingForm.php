<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Enrollment\StoreRegistrationCreditEvaluation;
use App\Models\AdmissionApplication;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\DocumentEvidence;
use App\Models\RegistrationCreditEvaluation;
use App\Models\StudentProfile;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Illuminate\Support\Collection;

/**
 * The Registrar's transfer crediting table: the SIA curriculum grouped by year and term, with
 * Credited / Need to take per subject and the TOR source line for credited rows.
 * Shared by first-enrollment crediting and later corrections on the Student record.
 */
class TransferCreditingForm
{
    public const StatusNeed = 'need';

    public const StatusCredited = 'credited';

    /**
     * Split layout for the full-screen crediting modal: the sticky preview of the transferee's TOR and other
     * submitted copies on the left (3/5) and the crediting table on the right (2/5).
     *
     * @param  list<mixed>  $components
     * @return list<mixed>
     */
    public static function withTorPreview(array $components, ?AdmissionApplication $application): array
    {
        return [
            Grid::make(['default' => 1, 'lg' => 5])
                ->extraAttributes(['class' => 'tala-crediting-split'])
                ->schema([
                    SchemaView::make('filament.admin.enrollments.crediting-tor-preview')
                        ->viewData(['copies' => self::previewCopies($application)])
                        ->columnSpan(['default' => 1, 'lg' => 3]),
                    Group::make($components)->columnSpan(['default' => 1, 'lg' => 2]),
                ]),
        ];
    }

    /**
     * Current submitted copies, TOR first (requirement code TOR, or a label naming the transcript).
     *
     * @return list<array{id: int, label: string, view_url: string, download_url: string}>
     */
    public static function previewCopies(?AdmissionApplication $application): array
    {
        if (! $application instanceof AdmissionApplication) {
            return [];
        }

        return $application->evidenceVersions()->with('admissionRequirement')->get()
            ->groupBy('admission_requirement_id')
            ->map(fn (Collection $versions): DocumentEvidence => $versions->sortByDesc('id')->first())
            ->sortBy(function (DocumentEvidence $evidence): int {
                $requirement = $evidence->admissionRequirement;
                $isTranscript = strtoupper((string) $requirement?->code) === 'TOR' || str_contains(strtolower((string) $requirement?->label), 'transcript');

                return $isTranscript ? 0 : 1;
            })
            ->map(fn (DocumentEvidence $evidence): array => [
                'id' => (int) $evidence->id,
                'label' => (string) ($evidence->admissionRequirement?->label ?? 'Submitted copy'),
                'view_url' => route('admissions.evidence.view', ['evidence' => $evidence->id]),
                'download_url' => route('admissions.evidence.download', ['evidence' => $evidence->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Closure(Get): ?int  $curriculumVersionId
     * @return list<mixed>
     */
    public static function components(Closure $curriculumVersionId, ?StudentProfile $flagStudent = null): array
    {
        return [
            Radio::make('same_curriculum')
                ->label('Is the previous school’s curriculum the same as SIA’s?')
                ->options([
                    'same' => 'Same curriculum (another SIA branch or the same curriculum)',
                    'different' => 'Different curriculum',
                ])
                ->live()
                // Same curriculum: fill every subject's TOR code, title and units with SIA's own (#99).
                ->afterStateUpdated(fn (Get $get, Set $set, ?string $state) => $set('rows', self::withSameCurriculumSource(
                    (array) $get('rows'),
                    $curriculumVersionId($get),
                    $state === 'same',
                )))
                ->required(),
            Group::make()
                ->visibleJs(<<<'JS'
                    !! $get('same_curriculum')
                    JS)
                ->schema(fn (Get $get): array => self::termSections($curriculumVersionId($get), $flagStudent)),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                TextInput::make('authority_reference')->label('Approval memo or reference')
                    ->placeholder('e.g., Memo 2026-014 or approving office')->required()->maxLength(255),
                DatePicker::make('approved_on')->label('Approval date')->required()->maxDate(today()),
            ]),
            Text::make('Recorded by you automatically. Earlier crediting stays in history.'),
        ];
    }

    /**
     * Form state for every subject of the curriculum, pre-filled from an earlier crediting on it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function rowsState(?int $curriculumVersionId, ?RegistrationCreditEvaluation $from = null, bool $sameCurriculum = false): array
    {
        if ($curriculumVersionId === null) {
            return [];
        }
        if ($sameCurriculum) {
            return self::withSameCurriculumSource(self::rowsState($curriculumVersionId, $from), $curriculumVersionId, true);
        }
        $lines = $from !== null && (int) $from->curriculum_version_id === $curriculumVersionId
            ? $from->creditLines()->get()->keyBy('curriculum_entry_id') : collect();
        $creditedIds = $from !== null && (int) $from->curriculum_version_id === $curriculumVersionId
            ? array_map(fn ($id): int => (int) $id, $from->credited_curriculum_entry_ids) : [];

        return CurriculumEntry::query()->where('curriculum_version_id', $curriculumVersionId)->pluck('id')
            ->mapWithKeys(function (int $id) use ($lines, $creditedIds): array {
                $line = $lines->get($id);

                return ['e'.$id => [
                    'status' => in_array($id, $creditedIds, true) ? self::StatusCredited : self::StatusNeed,
                    'tor_grade' => $line?->tor_grade,
                    'source_course_code' => $line?->source_course_code,
                    'source_course_title' => $line?->source_course_title,
                    'source_units' => $line?->source_units,
                    'remarks' => $line?->remarks,
                ]];
            })->all();
    }

    /**
     * Same curriculum: blank TOR code, title and units take the SIA subject's own values.
     * Different curriculum: values still equal to SIA's are cleared, so the other school's names are entered.
     * Values the Registrar typed are kept either way.
     *
     * @param  array<string, mixed>  $rows
     * @return array<string, mixed>
     */
    public static function withSameCurriculumSource(array $rows, ?int $curriculumVersionId, bool $same): array
    {
        if ($curriculumVersionId === null) {
            return $rows;
        }

        $subjects = CurriculumEntry::query()->with('courseSpecification.course')
            ->where('curriculum_version_id', $curriculumVersionId)->get()
            ->mapWithKeys(fn (CurriculumEntry $entry): array => ['e'.$entry->id => [
                'source_course_code' => (string) $entry->courseSpecification?->course?->code,
                'source_course_title' => (string) $entry->courseSpecification?->title,
                'source_units' => number_format((float) $entry->courseSpecification?->credit_units, 2, '.', ''),
            ]]);

        foreach ($subjects as $key => $sia) {
            $row = is_array($rows[$key] ?? null) ? $rows[$key] : ['status' => self::StatusNeed];
            foreach ($sia as $field => $value) {
                $current = $row[$field] ?? null;
                if ($same && blank($current)) {
                    $row[$field] = $value;
                } elseif (! $same && filled($current) && (string) $current === $value) {
                    $row[$field] = null;
                }
            }
            $rows[$key] = $row;
        }

        return $rows;
    }

    /**
     * Credit lines keyed by credited curriculum entry ID, ready for the record or correction action.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public static function creditLines(array $data): array
    {
        return collect($data['rows'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && ($row['status'] ?? null) === self::StatusCredited)
            ->mapWithKeys(fn (array $row, string $key): array => [(int) substr($key, 1) => [
                'source_course_code' => $row['source_course_code'] ?? null,
                'source_course_title' => $row['source_course_title'] ?? null,
                'source_units' => $row['source_units'] ?? null,
                'tor_grade' => $row['tor_grade'] ?? null,
                'remarks' => $row['remarks'] ?? null,
            ]])->all();
    }

    /** @param array<string, mixed> $data */
    public static function sameCurriculum(array $data): ?bool
    {
        return match ($data['same_curriculum'] ?? null) {
            'same' => true,
            'different' => false,
            default => null,
        };
    }

    public static function sameCurriculumState(?RegistrationCreditEvaluation $from): ?string
    {
        return match ($from?->same_curriculum) {
            true => 'same',
            false => 'different',
            default => null,
        };
    }

    /** @return list<Section|Text> */
    private static function termSections(?int $curriculumVersionId, ?StudentProfile $flagStudent): array
    {
        if ($curriculumVersionId === null) {
            return [Text::make('Choose the curriculum to list its subjects.')];
        }
        $flags = self::studentFlags($flagStudent);

        return CurriculumEntry::query()->with('courseSpecification.course')
            ->where('curriculum_version_id', $curriculumVersionId)->get()
            ->sortBy([
                fn (CurriculumEntry $a, CurriculumEntry $b): int => self::yearRank($a->year_level) <=> self::yearRank($b->year_level),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => self::termRank($a->term_label) <=> self::termRank($b->term_label),
                fn (CurriculumEntry $a, CurriculumEntry $b): int => [(int) $a->sequence, $a->id] <=> [(int) $b->sequence, $b->id],
            ])
            ->groupBy(fn (CurriculumEntry $entry): string => collect([
                is_numeric($entry->year_level) ? 'Year '.$entry->year_level : $entry->year_level,
                $entry->term_label,
            ])->filter()->implode(' · '))
            ->map(fn (Collection $entries, string $term): Section => Section::make($term)->compact()
                ->schema([Group::make()->statePath('rows')->schema(
                    $entries->map(fn (CurriculumEntry $entry): Group => self::row($entry, $flags[$entry->course_specification_id] ?? null))->values()->all(),
                )]))
            ->values()->all();
    }

    /** Year level as a number: "1", "1st Year" and "First Year" all rank 1; unknown labels go last. */
    private static function yearRank(?string $yearLevel): int
    {
        $label = strtolower(trim((string) $yearLevel));
        if (preg_match('/\d+/', $label, $match) === 1) {
            return (int) $match[0];
        }

        foreach (['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5] as $word => $rank) {
            if (str_contains($label, $word)) {
                return $rank;
            }
        }

        return 99;
    }

    /** Curriculum term order: first semester, second semester, then summer or special terms. */
    private static function termRank(?string $termLabel): int
    {
        $label = strtolower(trim((string) $termLabel));

        return match (true) {
            str_contains($label, 'first') || str_contains($label, '1st') => 1,
            str_contains($label, 'second') || str_contains($label, '2nd') => 2,
            str_contains($label, 'summer') || str_contains($label, 'special') || str_contains($label, 'mid') => 3,
            default => 9,
        };
    }

    private static function row(CurriculumEntry $entry, ?string $flag): Group
    {
        $specification = $entry->courseSpecification;
        $code = $specification->course->code;
        $units = number_format((float) $specification->credit_units, 2, '.', '');
        $isCredited = fn (Get $get): bool => $get('status') === self::StatusCredited;
        $needsSource = fn (Get $get): bool => $isCredited($get) && $get('../../same_curriculum') !== 'same';

        return Group::make()->statePath('e'.$entry->id)->schema([
            Grid::make(['default' => 1, 'lg' => 12])->schema([
                Text::make(collect(["{$code} · {$specification->title} · {$units} units", $flag])->filter()->implode(' · '))
                    ->weight('medium')->columnSpan(['lg' => 5]),
                ToggleButtons::make('status')->hiddenLabel()->inline()->grouped()
                    ->options([
                        self::StatusCredited => 'Credited',
                        self::StatusNeed => 'Need to take',
                    ])
                    ->default(self::StatusNeed)
                    ->columnSpan(['lg' => 7]),
            ]),
            Grid::make(['default' => 2, 'lg' => 6])
                ->extraAttributes(['class' => 'tala-crediting-source'])
                ->visibleJs(<<<'JS'
                    $get('status') === 'credited'
                    JS)
                ->schema([
                    TextInput::make('tor_grade')->label('TOR grade')->placeholder('As printed')
                        ->required($isCredited)->maxLength(StoreRegistrationCreditEvaluation::TorGradeMaxLength)->columnSpan(['lg' => 2]),
                    TextInput::make('source_course_code')->label('TOR code')->placeholder($code)
                        ->required($needsSource)->maxLength(StoreRegistrationCreditEvaluation::SourceCodeMaxLength)->columnSpan(['lg' => 2]),
                    TextInput::make('source_units')->label('TOR units')->placeholder($units)->numeric()->minValue(0)->maxValue(30)
                        ->columnSpan(['lg' => 2]),
                    TextInput::make('source_course_title')->label('TOR course title')->placeholder($specification->title)
                        ->required($needsSource)->maxLength(StoreRegistrationCreditEvaluation::SourceTitleMaxLength)->columnSpanFull(),
                    TextInput::make('remarks')->label('Remarks')->maxLength(StoreRegistrationCreditEvaluation::RemarksMaxLength)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Correction cues for subjects the Student already passed or is taking at SIA.
     *
     * @return array<int, string> keyed by course specification ID
     */
    private static function studentFlags(?StudentProfile $student): array
    {
        if ($student === null) {
            return [];
        }

        return app(OfficialCourseResultProjection::class)->forStudent($student)
            ->filter(fn (array $attempt): bool => $attempt['course_specification'] !== null)
            ->groupBy(fn (array $attempt): int => (int) $attempt['course_specification']->id)
            ->map(fn (Collection $attempts): ?string => match (true) {
                $attempts->contains(fn (array $attempt): bool => is_numeric($attempt['result']) && (float) $attempt['result'] <= 4.00) => 'Passed at SIA',
                $attempts->contains(fn (array $attempt): bool => $attempt['event'] === null && $attempt['course_enrollment']->is_current
                    && $attempt['course_enrollment']->status === CourseEnrollment::StatusActive) => 'Taking now at SIA',
                default => null,
            })
            ->filter()->all();
    }
}
