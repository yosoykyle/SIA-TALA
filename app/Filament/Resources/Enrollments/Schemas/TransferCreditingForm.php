<?php

namespace App\Filament\Resources\Enrollments\Schemas;

use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Enrollment\StoreRegistrationCreditEvaluation;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
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
use Illuminate\Support\Collection;

/**
 * The Registrar's transfer crediting table: the SIA curriculum grouped by year and term, with
 * Credited / Credit with bridging / Need to take per subject and the TOR source line for credited rows.
 * Shared by first-enrollment crediting and later corrections on the Student record.
 */
class TransferCreditingForm
{
    public const StatusNeed = 'need';

    public const StatusCredited = 'credited';

    public const StatusBridging = 'bridging';

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
    public static function rowsState(?int $curriculumVersionId, ?RegistrationCreditEvaluation $from = null): array
    {
        if ($curriculumVersionId === null) {
            return [];
        }
        $lines = $from !== null && (int) $from->curriculum_version_id === $curriculumVersionId
            ? $from->creditLines()->get()->keyBy('curriculum_entry_id') : collect();
        $creditedIds = $from !== null && (int) $from->curriculum_version_id === $curriculumVersionId
            ? array_map(fn ($id): int => (int) $id, $from->credited_curriculum_entry_ids) : [];

        return CurriculumEntry::query()->where('curriculum_version_id', $curriculumVersionId)->pluck('id')
            ->mapWithKeys(function (int $id) use ($lines, $creditedIds): array {
                $line = $lines->get($id);

                return ['e'.$id => [
                    'status' => match (true) {
                        ! in_array($id, $creditedIds, true) => self::StatusNeed,
                        $line?->bridging_units !== null => self::StatusBridging,
                        default => self::StatusCredited,
                    },
                    'tor_grade' => $line?->tor_grade,
                    'source_course_code' => $line?->source_course_code,
                    'source_course_title' => $line?->source_course_title,
                    'source_units' => $line?->source_units,
                    'bridging_units' => $line?->bridging_units,
                    'remarks' => $line?->remarks,
                ]];
            })->all();
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
            ->filter(fn (mixed $row): bool => is_array($row) && in_array($row['status'] ?? null, [self::StatusCredited, self::StatusBridging], true))
            ->mapWithKeys(fn (array $row, string $key): array => [(int) substr($key, 1) => [
                'source_course_code' => $row['source_course_code'] ?? null,
                'source_course_title' => $row['source_course_title'] ?? null,
                'source_units' => $row['source_units'] ?? null,
                'tor_grade' => $row['tor_grade'] ?? null,
                'bridging_units' => $row['status'] === self::StatusBridging ? ($row['bridging_units'] ?? null) : null,
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
            ->where('curriculum_version_id', $curriculumVersionId)->orderBy('sequence')->get()
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

    private static function row(CurriculumEntry $entry, ?string $flag): Group
    {
        $specification = $entry->courseSpecification;
        $code = $specification->course->code;
        $units = number_format((float) $specification->credit_units, 2, '.', '');
        $isCredited = fn (Get $get): bool => in_array($get('status'), [self::StatusCredited, self::StatusBridging], true);
        $needsSource = fn (Get $get): bool => $isCredited($get) && $get('../../same_curriculum') !== 'same';

        return Group::make()->statePath('e'.$entry->id)->schema([
            Grid::make(['default' => 1, 'lg' => 12])->schema([
                Text::make(collect(["{$code} · {$specification->title} · {$units} units", $flag])->filter()->implode(' · '))
                    ->weight('medium')->columnSpan(['lg' => 5]),
                ToggleButtons::make('status')->hiddenLabel()->inline()->grouped()
                    ->options([
                        self::StatusCredited => 'Credited',
                        self::StatusBridging => 'Credit with bridging',
                        self::StatusNeed => 'Need to take',
                    ])
                    ->default(self::StatusNeed)
                    ->columnSpan(['lg' => 7]),
            ]),
            Grid::make(['default' => 2, 'lg' => 12])
                ->visibleJs(<<<'JS'
                    ['credited', 'bridging'].includes($get('status'))
                    JS)
                ->schema([
                    TextInput::make('tor_grade')->label('TOR grade')->placeholder('As printed')
                        ->required($isCredited)->maxLength(StoreRegistrationCreditEvaluation::TorGradeMaxLength)->columnSpan(['lg' => 2]),
                    TextInput::make('source_course_code')->label('Previous course code')->placeholder($code)
                        ->required($needsSource)->maxLength(StoreRegistrationCreditEvaluation::SourceCodeMaxLength)->columnSpan(['lg' => 2]),
                    TextInput::make('source_course_title')->label('Previous course title')->placeholder($specification->title)
                        ->required($needsSource)->maxLength(StoreRegistrationCreditEvaluation::SourceTitleMaxLength)->columnSpan(['default' => 2, 'lg' => 4]),
                    TextInput::make('source_units')->label('Previous units')->placeholder($units)->numeric()->minValue(0)->maxValue(30)
                        ->columnSpan(['lg' => 2]),
                    TextInput::make('bridging_units')->label('Bridging units')->numeric()->minValue(0.5)->maxValue((float) $units)
                        ->placeholder('Units owed')
                        ->required(fn (Get $get): bool => $get('status') === self::StatusBridging)
                        ->visibleJs(<<<'JS'
                            $get('status') === 'bridging'
                            JS)
                        ->columnSpan(['lg' => 2]),
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
