<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Term;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CourseInfolist
{
    public const UsagePerPage = 10;

    public const UsageStatusActive = 'active';

    public const UsageStatusDraft = 'draft';

    public const UsageStatusRetired = 'retired';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.catalog.course-detail')
                ->viewData(function (Course $record, $livewire): array {
                    $record->loadMissing(['specifications.components', 'specifications.requirements.relatedCourse']);
                    $revisions = $record->specifications
                        ->sortBy([
                            fn (CourseSpecification $a, CourseSpecification $b): int => self::stateRank($a->state) <=> self::stateRank($b->state),
                            fn (CourseSpecification $a, CourseSpecification $b): int => $b->id <=> $a->id,
                        ])
                        ->values();

                    return [
                        'course' => $record,
                        'specification' => method_exists($livewire, 'selectedRevision') ? $livewire->selectedRevision() : self::primarySpecification($record),
                        'revisions' => $revisions,
                        'canManage' => auth()->user()?->can('update', $record) ?? false,
                        'oversightTermId' => property_exists($livewire, 'oversightTermId') ? $livewire->oversightTermId : null,
                        'usage' => method_exists($livewire, 'curriculumUsageData')
                            ? $livewire->curriculumUsageData()
                            : self::curriculumUsage($record),
                    ];
                })
                ->columnSpanFull(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function usageStatusOptions(): array
    {
        return [
            self::UsageStatusActive => 'Active',
            self::UsageStatusDraft => 'Draft',
            self::UsageStatusRetired => 'Retired',
        ];
    }

    /**
     * Build the filtered, paginated "Used in programs & curricula" listing for a subject.
     *
     * @return array{
     *     rows: Collection<int, array{curriculum: CurriculumVersion, placements: Collection<int, string>, status: string}>,
     *     programOptions: array<int, string>,
     *     totalCount: int,
     *     filteredCount: int,
     *     perPage: int,
     *     currentPage: int,
     *     lastPage: int,
     *     from: int,
     *     to: int,
     *     hasFilters: bool
     * }
     */
    public static function curriculumUsage(
        Course $course,
        string $search = '',
        string $programFilter = '',
        string $statusFilter = '',
        int $page = 1,
        int $perPage = self::UsagePerPage,
    ): array {
        $placements = self::curriculumPlacements($course);

        $programOptions = $placements
            ->map(fn (array $row): Program => $row['curriculum']->program)
            ->unique('id')
            ->sortBy('code')
            ->mapWithKeys(fn (Program $program): array => [$program->id => (string) $program->code])
            ->all();

        $search = trim($search);
        $programId = (int) $programFilter;
        $statusFilter = array_key_exists($statusFilter, self::usageStatusOptions()) ? $statusFilter : '';

        $filtered = $placements
            ->when($programId > 0, fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => (int) $row['curriculum']->program_id === $programId))
            ->when($statusFilter !== '', fn (Collection $rows): Collection => $rows
                ->filter(fn (array $row): bool => $row['status'] === $statusFilter))
            ->when($search !== '', fn (Collection $rows): Collection => $rows
                ->filter(function (array $row) use ($search): bool {
                    $curriculum = $row['curriculum'];
                    $haystack = implode(' ', [
                        (string) $curriculum->program?->code,
                        (string) $curriculum->program?->name,
                        (string) $curriculum->name,
                        (string) $curriculum->version_code,
                        $row['placements']->implode(' '),
                    ]);

                    return Str::contains($haystack, $search, ignoreCase: true);
                }))
            ->values();

        $filteredCount = $filtered->count();
        $perPage = max(1, $perPage);
        $lastPage = max(1, (int) ceil($filteredCount / $perPage));
        $currentPage = min(max(1, $page), $lastPage);
        $rows = $filtered->forPage($currentPage, $perPage)->values();
        $from = $filteredCount > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;

        return [
            'rows' => $rows,
            'programOptions' => $programOptions,
            'totalCount' => $placements->count(),
            'filteredCount' => $filteredCount,
            'perPage' => $perPage,
            'currentPage' => $currentPage,
            'lastPage' => $lastPage,
            'from' => $from,
            'to' => $filteredCount > 0 ? $from + $rows->count() - 1 : 0,
            'hasFilters' => $search !== '' || $programId > 0 || $statusFilter !== '',
        ];
    }

    /**
     * Every curriculum that places any revision of this subject, ordered Active → Draft → Retired, then program and name.
     *
     * @return Collection<int, array{curriculum: CurriculumVersion, placements: Collection<int, string>, status: string}>
     */
    public static function curriculumPlacements(Course $course): Collection
    {
        $course->loadMissing(['specifications.curriculumEntries.curriculumVersion.program']);

        return $course->specifications
            ->flatMap(fn (CourseSpecification $spec): Collection => $spec->curriculumEntries)
            ->filter(fn (CurriculumEntry $entry): bool => $entry->curriculumVersion instanceof CurriculumVersion
                && $entry->curriculumVersion->program instanceof Program)
            ->groupBy('curriculum_version_id')
            ->map(function (Collection $entries): array {
                /** @var CurriculumEntry $first */
                $first = $entries->first();
                $curriculum = $first->curriculumVersion;

                $placements = $entries
                    ->sortBy(fn (CurriculumEntry $entry): string => sprintf('%03d-%d', self::parseYearNumber($entry->year_level), self::termSort((string) $entry->term_type)))
                    ->map(fn (CurriculumEntry $entry): string => self::placementLabel($entry))
                    ->unique()
                    ->values();

                return [
                    'curriculum' => $curriculum,
                    'placements' => $placements,
                    'status' => self::usageStatus($curriculum),
                ];
            })
            ->sortBy(function (array $row): string {
                $stateOrder = match ($row['status']) {
                    self::UsageStatusActive => 0,
                    self::UsageStatusDraft => 1,
                    default => 2,
                };

                return sprintf('%d-%s-%s', $stateOrder, (string) $row['curriculum']->program?->code, (string) $row['curriculum']->name);
            })
            ->values();
    }

    private static function usageStatus(CurriculumVersion $curriculum): string
    {
        return match ($curriculum->state) {
            CurriculumVersion::StateActive => self::UsageStatusActive,
            CurriculumVersion::StateDraft => self::UsageStatusDraft,
            default => self::UsageStatusRetired,
        };
    }

    private static function placementLabel(CurriculumEntry $entry): string
    {
        $year = self::parseYearNumber($entry->year_level);
        $yearText = match ($year) {
            0 => 'Year not set',
            1 => '1st Year',
            2 => '2nd Year',
            3 => '3rd Year',
            default => "{$year}th Year",
        };
        $termText = match ($entry->term_type) {
            Term::TypeFirstSemester => 'First Semester',
            Term::TypeSecondSemester => 'Second Semester',
            Term::TypeSpecialTerm => 'Summer',
            default => filled($entry->term_label) ? (string) $entry->term_label : (string) $entry->term_type,
        };

        return "{$yearText} · {$termText}";
    }

    private static function termSort(string $termType): int
    {
        return match ($termType) {
            Term::TypeFirstSemester => 1,
            Term::TypeSecondSemester => 2,
            Term::TypeSpecialTerm => 3,
            default => 4,
        };
    }

    private static function parseYearNumber(mixed $yearLevel): int
    {
        if (is_int($yearLevel)) {
            return $yearLevel;
        }

        $normalized = strtolower(trim((string) $yearLevel));

        if (preg_match('/\d+/', $normalized, $matches) === 1) {
            return (int) $matches[0];
        }

        return match (true) {
            str_contains($normalized, 'first') => 1,
            str_contains($normalized, 'second') => 2,
            str_contains($normalized, 'third') => 3,
            str_contains($normalized, 'fourth') => 4,
            str_contains($normalized, 'fifth') => 5,
            str_contains($normalized, 'sixth') => 6,
            str_contains($normalized, 'seventh') => 7,
            str_contains($normalized, 'eighth') => 8,
            default => 0,
        };
    }

    private static function stateRank(string $state): int
    {
        return match ($state) {
            CourseSpecification::StateActive => 1,
            CourseSpecification::StateDraft => 2,
            default => 3,
        };
    }

    private static function activeSpecification(Course $record): ?CourseSpecification
    {
        $record->loadMissing('specifications');

        return $record->specifications
            ->where('state', CourseSpecification::StateActive)
            ->sortByDesc('id')
            ->first();
    }

    private static function primarySpecification(Course $record): ?CourseSpecification
    {
        return self::activeSpecification($record)
            ?? $record->specifications->sortByDesc('id')->first();
    }
}
