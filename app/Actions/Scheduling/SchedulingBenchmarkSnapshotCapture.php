<?php

namespace App\Actions\Scheduling;

use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchedulingBenchmarkSnapshotCapture
{
    public function __construct(
        private readonly ScheduleGenerationService $generationService,
    ) {}

    /**
     * @return array{run:ScheduleGenerationRun,snapshot:array<string,mixed>}
     */
    public function capture(): array
    {
        $startingTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $term = Term::query()->where('type', Term::TypeSecondSemester)->sole();
            $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
            $run = $this->generationService->generate($term, $registrar);
            $snapshot = $run->getAttribute('input_snapshot');

            if (! is_array($snapshot)
                || ($snapshot['contract_version'] ?? null) !== 'tal94-demand-v2') {
                throw new RuntimeException('The representative scheduling snapshot is not TAL-94 V2.');
            }

            $snapshot = $this->adaptCurrentMinSnapshotToHistoricalBenchmark($snapshot);
        } finally {
            while (DB::transactionLevel() > $startingTransactionLevel) {
                DB::rollBack();
            }
        }

        return [
            'run' => $run,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Preserve the accepted TAL-96B3 12-faculty, 20:00 calibration shape
     * without changing the current nine-faculty, 21:00 MIN fixture.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function adaptCurrentMinSnapshotToHistoricalBenchmark(array $snapshot): array
    {
        $composition = [
            'demands' => count($snapshot['scheduling_demands'] ?? []),
            'faculty' => count($snapshot['faculty'] ?? []),
            'rooms' => count($snapshot['rooms'] ?? []),
            'time_slots' => count($snapshot['time_slots'] ?? []),
        ];

        if ($composition === [
            'demands' => 54,
            'faculty' => 12,
            'rooms' => 6,
            'time_slots' => 156,
        ]) {
            return $snapshot;
        }

        if ($composition !== [
            'demands' => 54,
            'faculty' => 9,
            'rooms' => 6,
            'time_slots' => 168,
        ]) {
            return $snapshot;
        }

        $facultyRows = collect($snapshot['faculty']);
        $qualificationRows = collect($snapshot['faculty_qualifications'] ?? []);
        $sourceFacultyIds = $qualificationRows
            ->groupBy('faculty_user_id')
            ->filter(
                fn ($rows): bool => $rows->pluck('course_id')->filter()->unique()->count() > 1,
            )
            ->sortByDesc(fn ($rows): int => $rows->count())
            ->keys()
            ->take(3)
            ->map(fn (mixed $facultyId): int => (int) $facultyId)
            ->values();

        if ($sourceFacultyIds->count() !== 3) {
            return $snapshot;
        }

        $nextFacultyId = (int) $facultyRows->max('faculty_id') + 1;

        foreach ($sourceFacultyIds as $offset => $sourceFacultyId) {
            $sourceFaculty = $facultyRows->firstWhere('faculty_id', $sourceFacultyId);
            $courseId = $qualificationRows
                ->where('faculty_user_id', $sourceFacultyId)
                ->pluck('course_id')
                ->filter()
                ->unique()
                ->sort()
                ->first();

            if (! is_array($sourceFaculty) || $courseId === null) {
                return $snapshot;
            }

            $newFacultyId = $nextFacultyId + $offset;
            $sourceFaculty['faculty_id'] = $newFacultyId;
            $snapshot['faculty'][] = $sourceFaculty;

            foreach ($snapshot['faculty_qualifications'] as &$qualification) {
                if ((int) ($qualification['faculty_user_id'] ?? 0) === $sourceFacultyId
                    && (int) ($qualification['course_id'] ?? 0) === (int) $courseId) {
                    $qualification['faculty_user_id'] = $newFacultyId;
                }
            }
            unset($qualification);

            foreach ($snapshot['scheduling_demands'] as &$demand) {
                if ((int) ($demand['course_id'] ?? 0) !== (int) $courseId) {
                    continue;
                }

                $demand['eligible_faculty_user_ids'] = array_map(
                    fn (mixed $facultyId): int => (int) $facultyId === $sourceFacultyId
                        ? $newFacultyId
                        : (int) $facultyId,
                    $demand['eligible_faculty_user_ids'] ?? [],
                );

                foreach ($demand['faculty_load_options'] ?? [] as &$option) {
                    if ((int) ($option['faculty_user_id'] ?? 0) === $sourceFacultyId) {
                        $option['faculty_user_id'] = $newFacultyId;
                    }
                }
                unset($option);

                if ((int) ($demand['fixed_faculty_user_id'] ?? 0) === $sourceFacultyId) {
                    $demand['fixed_faculty_user_id'] = $newFacultyId;
                }
            }
            unset($demand);
        }

        $snapshot['time_slots'] = collect($snapshot['time_slots'])
            ->filter(fn (array $slot): bool => ($slot['ends_at'] ?? null) <= '20:00:00')
            ->values()
            ->map(function (array $slot, int $index): array {
                $slot['time_slot_id'] = $index + 1;

                return $slot;
            })
            ->all();
        $snapshot['term']['scheduling_day_ends_at'] = '20:00:00';

        return $snapshot;
    }
}
