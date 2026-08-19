<?php

namespace App\Actions\Scheduling;

use App\Actions\SystemAdministration\SchedulingAcceptanceScenarioCatalog;
use App\Models\ScheduleGenerationRun;
use App\Models\Section;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SchedulingOperatingEnvelopeSnapshotCapture
{
    public function __construct(
        private readonly ScheduleGenerationService $generationService,
        private readonly SchedulingAcceptanceScenarioCatalog $scenarioCatalog,
    ) {}

    /**
     * @return array{
     *     run:ScheduleGenerationRun,
     *     snapshot:array<string,mixed>,
     *     snapshot_sha256:string,
     *     manifest:array<string,mixed>,
     *     composition:array<string,int>,
     *     evidence_labels:array{sections:array<int,string>,faculty:array<int,string>}
     * }
     */
    public function capture(string $scenario): array
    {
        $scenario = $this->scenarioCatalog->normalize($scenario);
        $manifest = $this->scenarioCatalog->manifest($scenario);
        $startingTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();

        try {
            $term = Term::query()->where('type', Term::TypeSecondSemester)->sole();
            $registrar = User::query()->where('email', 'registrar.demo@example.test')->sole();
            $run = $this->generationService->generate($term, $registrar);
            $snapshot = $run->getAttribute('input_snapshot');

            if (! is_array($snapshot)
                || ($snapshot['contract_version'] ?? null) !== ScheduleGenerationRun::ContractVersion) {
                throw new RuntimeException('The captured scheduling snapshot does not use the current timetable contract.');
            }

            $composition = $this->composition($snapshot);

            if ($composition['demands'] !== $manifest['counts']['scheduling_demands']
                || $composition['faculty'] !== $manifest['counts']['faculty']) {
                throw new RuntimeException('The captured snapshot does not match the exact scenario manifest.');
            }

            $snapshotSha256 = $this->normalizedHash($snapshot);
            $evidenceLabels = $this->evidenceLabels($snapshot);
        } finally {
            while (DB::transactionLevel() > $startingTransactionLevel) {
                DB::rollBack();
            }
        }

        $run->setAttribute('input_snapshot', $snapshot);

        return [
            'run' => $run,
            'snapshot' => $snapshot,
            'snapshot_sha256' => $snapshotSha256,
            'manifest' => $manifest,
            'composition' => $composition,
            'evidence_labels' => $evidenceLabels,
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, int>
     */
    public function composition(array $snapshot): array
    {
        return [
            'demands' => count($snapshot['scheduling_demands'] ?? []),
            'faculty' => count($snapshot['faculty'] ?? []),
            'rooms' => count($snapshot['rooms'] ?? []),
            'time_slots' => count($snapshot['time_slots'] ?? []),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function normalizedHash(array $snapshot): string
    {
        unset($snapshot['captured_at'], $snapshot['run_metadata']);
        $normalized = $this->normalize($snapshot);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{sections:array<int,string>,faculty:array<int,string>}
     */
    private function evidenceLabels(array $snapshot): array
    {
        $sectionIds = collect($snapshot['scheduling_demands'] ?? [])
            ->pluck('section_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $sections = Section::query()
            ->whereKey($sectionIds->all())
            ->pluck('code', 'id')
            ->mapWithKeys(fn (string $code, int|string $id): array => [(int) $id => $code])
            ->all();
        $facultyIds = collect($snapshot['faculty'] ?? [])
            ->pluck('faculty_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $faculty = $facultyIds
            ->mapWithKeys(fn (int $id, int $index): array => [
                $id => sprintf('Faculty %02d', $index + 1),
            ])
            ->all();

        return [
            'sections' => $sections,
            'faculty' => $faculty,
        ];
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
    }
}
