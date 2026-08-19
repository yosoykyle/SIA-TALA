<?php

namespace App\Actions\Scheduling;

use App\Models\ScheduleGenerationRun;
use Illuminate\Support\Arr;
use RuntimeException;

final class SchedulingBenchmarkDatasetFactory
{
    /** @var list<string> */
    public const Tiers = [
        'reduced',
        'representative',
        'proportional-2x',
        'contention-2x',
        'proportional-4x',
    ];

    private const CopyOffset = 1_000_000;

    /** @var list<string> */
    private const RemappedIdFields = [
        'calendar_event_id',
        'cohort_or_student_group_id',
        'course_component_id',
        'course_id',
        'course_specification_id',
        'curriculum_entry_id',
        'qualification_id',
        'scheduling_demand_id',
        'section_delivery_group_id',
        'section_id',
        'subject_id',
        'term_load_override_id',
        'term_offering_id',
    ];

    /** @var list<string> */
    private const DuplicatedListKeys = [
        'subjects',
        'scheduling_demands',
        'sections',
        'section_delivery_groups',
        'faculty_qualifications',
        'term_offerings',
        'student_cohort_groups',
        'fixed_assignments',
    ];

    /**
     * @param  array<string, mixed>  $representativeSnapshot
     * @return array<string, mixed>
     */
    public function make(array $representativeSnapshot, string $tier): array
    {
        $this->assertRepresentativeSnapshot($representativeSnapshot);

        return match ($tier) {
            'reduced' => $this->reducedSnapshot($representativeSnapshot),
            'representative' => $representativeSnapshot,
            'proportional-2x' => $this->proportionalSnapshot($representativeSnapshot, 2),
            'contention-2x' => $this->contentionSnapshot($representativeSnapshot),
            'proportional-4x' => $this->proportionalSnapshot($representativeSnapshot, 4),
            default => throw new RuntimeException("Unknown scheduling benchmark tier: {$tier}."),
        };
    }

    /**
     * @return array{
     *     label:string,
     *     purpose:string,
     *     institutional_minimum:bool,
     *     client_student_population:int|null,
     *     client_program_count:int|null,
     *     client_cohort_count:int|null
     * }
     */
    public function definition(string $tier): array
    {
        return match ($tier) {
            'reduced' => [
                'label' => 'Reduced technical tier',
                'purpose' => 'A resource-closed subset used to verify smaller-model behavior; it is not a minimum supported institution.',
                'institutional_minimum' => false,
                'client_student_population' => null,
                'client_program_count' => null,
                'client_cohort_count' => null,
            ],
            'representative' => [
                'label' => 'Client-representative tier',
                'purpose' => 'The implemented scheduling workload for the current client curricula and six cohorts.',
                'institutional_minimum' => false,
                'client_student_population' => 47,
                'client_program_count' => 3,
                'client_cohort_count' => 6,
            ],
            'proportional-2x' => [
                'label' => 'Proportional growth 2x tier',
                'purpose' => 'A synthetic compute-scaling tier that doubles demands, faculty, and rooms while preserving the time grid.',
                'institutional_minimum' => false,
                'client_student_population' => null,
                'client_program_count' => null,
                'client_cohort_count' => null,
            ],
            'contention-2x' => [
                'label' => 'Contention growth 2x tier',
                'purpose' => 'A synthetic stress tier that doubles demands while sharing the representative faculty and rooms.',
                'institutional_minimum' => false,
                'client_student_population' => null,
                'client_program_count' => null,
                'client_cohort_count' => null,
            ],
            'proportional-4x' => [
                'label' => 'Proportional growth 4x tier',
                'purpose' => 'A synthetic upper bounded tier that quadruples demands, faculty, and rooms while preserving the time grid.',
                'institutional_minimum' => false,
                'client_student_population' => null,
                'client_program_count' => null,
                'client_cohort_count' => null,
            ],
            default => throw new RuntimeException("Unknown scheduling benchmark tier: {$tier}."),
        };
    }

    /**
     * @param  array<string, mixed>  $representativeSnapshot
     * @param  list<string>  $tiers
     * @return array<string, array<string, mixed>>
     */
    public function makeMany(array $representativeSnapshot, array $tiers): array
    {
        $requested = array_fill_keys($tiers, true);
        $datasets = [];

        foreach (self::Tiers as $tier) {
            if (isset($requested[$tier])) {
                $datasets[$tier] = $this->make($representativeSnapshot, $tier);
            }
        }

        return $datasets;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{demands:int,faculty:int,rooms:int,time_slots:int}
     */
    public function composition(array $snapshot): array
    {
        return [
            'demands' => count($this->list($snapshot['scheduling_demands'] ?? null)),
            'faculty' => count($this->list($snapshot['faculty'] ?? null)),
            'rooms' => count($this->list($snapshot['rooms'] ?? null)),
            'time_slots' => count($this->list($snapshot['time_slots'] ?? null)),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function requiredAssignmentCount(array $snapshot): int
    {
        return (int) collect($this->list($snapshot['scheduling_demands'] ?? null))
            ->sum(fn (array $demand): int => max(1, (int) ($demand['meeting_count'] ?? 1)));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{subjects:int,sections:int,offerings:int,demands:int,faculty:int,rooms:int,time_slots:int}
     */
    public function evidenceComposition(array $snapshot): array
    {
        return [
            'subjects' => count($this->list($snapshot['subjects'] ?? null)),
            'sections' => count($this->list($snapshot['sections'] ?? null)),
            'offerings' => count($this->list($snapshot['term_offerings'] ?? null)),
            ...$this->composition($snapshot),
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public function normalizedHash(array $snapshot): string
    {
        $normalized = $snapshot;
        $normalized['captured_at'] = null;
        Arr::set($normalized, 'run_metadata.solver_run_id', 0);
        Arr::set($normalized, 'run_metadata.requested_by', 0);

        return hash('sha256', json_encode(
            $this->canonicalize($normalized),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function reducedSnapshot(array $snapshot): array
    {
        $reduced = $snapshot;
        $facultyRows = array_slice($this->list($snapshot['faculty'] ?? null), 0, 6);
        $facultyIds = $this->integerValues($facultyRows, 'faculty_id');

        if (count($facultyIds) !== 6) {
            throw new RuntimeException('The representative snapshot cannot produce the six-faculty reduced tier.');
        }

        $demands = collect($this->list($snapshot['scheduling_demands'] ?? null))
            ->filter(function (array $demand) use ($facultyIds): bool {
                $references = $this->demandFacultyReferences($demand);

                return $references !== [] && array_diff($references, $facultyIds) === [];
            })
            ->take(27)
            ->values()
            ->all();

        if (count($demands) !== 27) {
            throw new RuntimeException('The representative snapshot cannot produce a natural 27-demand reduced tier from six faculty.');
        }

        $demandIds = $this->integerValues($demands, 'scheduling_demand_id');
        $sectionIds = $this->integerValues($demands, 'section_id');
        $groupIds = $this->integerValues($demands, 'section_delivery_group_id');
        $offeringIds = $this->integerValues($demands, 'term_offering_id');
        $courseIds = $this->integerValues($demands, 'course_id');

        $reduced['scheduling_demands'] = $demands;
        $reduced['sections'] = $this->filterByIds($snapshot, 'sections', 'section_id', $sectionIds);
        $reduced['section_delivery_groups'] = $this->filterByIds(
            $snapshot,
            'section_delivery_groups',
            'section_delivery_group_id',
            $groupIds,
        );
        $reduced['student_cohort_groups'] = $this->filterByIds(
            $snapshot,
            'student_cohort_groups',
            'section_delivery_group_id',
            $groupIds,
        );
        $reduced['term_offerings'] = $this->filterByIds(
            $snapshot,
            'term_offerings',
            'term_offering_id',
            $offeringIds,
        );
        $reduced['subjects'] = $this->filterByIds($snapshot, 'subjects', 'course_id', $courseIds);
        $reduced['faculty_qualifications'] = array_values(array_filter(
            $this->filterByIds(
                $snapshot,
                'faculty_qualifications',
                'scheduling_demand_id',
                $demandIds,
            ),
            fn (array $row): bool => in_array((int) ($row['faculty_user_id'] ?? 0), $facultyIds, true),
        ));
        $reduced['fixed_assignments'] = $this->filterByIds(
            $snapshot,
            'fixed_assignments',
            'scheduling_demand_id',
            $demandIds,
        );
        $reduced['faculty'] = $facultyRows;
        $reduced['rooms'] = $this->reducedRooms($snapshot, $demands);
        $roomIds = $this->integerValues($reduced['rooms'], 'room_id');
        $reduced['faculty_availability'] = array_values(array_filter(
            $this->list($snapshot['faculty_availability'] ?? null),
            fn (array $row): bool => in_array(
                (int) ($row['faculty_id'] ?? $row['faculty_user_id'] ?? 0),
                $facultyIds,
                true,
            ),
        ));
        $reduced['calendar_blocks'] = array_values(array_filter(
            $this->list($snapshot['calendar_blocks'] ?? null),
            fn (array $row): bool => $this->blockTargetsSelectedResources($row, $facultyIds, $roomIds),
        ));

        return $reduced;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function proportionalSnapshot(array $snapshot, int $copies): array
    {
        $scaled = $snapshot;

        foreach ([...self::DuplicatedListKeys, 'rooms', 'faculty'] as $key) {
            $scaled[$key] = [];
        }

        $scaled['faculty_availability'] = [];
        $scaled['calendar_blocks'] = [];

        foreach (range(0, $copies - 1) as $copyIndex) {
            foreach (self::DuplicatedListKeys as $key) {
                $scaled[$key] = [
                    ...$scaled[$key],
                    ...array_map(
                        fn (array $row): array => $this->remapCopy($row, $copyIndex),
                        $this->list($snapshot[$key] ?? null),
                    ),
                ];
            }

            $scaled['rooms'] = [
                ...$scaled['rooms'],
                ...array_map(
                    fn (array $row): array => $this->remapCopy($row, $copyIndex),
                    $this->list($snapshot['rooms'] ?? null),
                ),
            ];
            $scaled['faculty'] = [
                ...$scaled['faculty'],
                ...array_map(
                    fn (array $row): array => $this->remapCopy($row, $copyIndex),
                    $this->list($snapshot['faculty'] ?? null),
                ),
            ];
        }

        return $scaled;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function contentionSnapshot(array $snapshot): array
    {
        $contention = $snapshot;

        foreach (self::DuplicatedListKeys as $key) {
            $contention[$key] = [];

            foreach (range(0, 1) as $copyIndex) {
                $contention[$key] = [
                    ...$contention[$key],
                    ...array_map(
                        fn (array $row): array => $this->remapCopy(
                            $row,
                            $copyIndex,
                            shareFaculty: true,
                            shareRooms: true,
                        ),
                        $this->list($snapshot[$key] ?? null),
                    ),
                ];
            }
        }

        $contention['faculty'] = $snapshot['faculty'];
        $contention['rooms'] = $snapshot['rooms'];
        $contention['faculty_availability'] = [];
        $contention['calendar_blocks'] = [];
        $contention['term']['default_max_units'] = $this->doubleDecimal(
            data_get($snapshot, 'term.default_max_units'),
        );

        return $this->doubleNestedFacultyCaps($contention);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function remapCopy(
        array $record,
        int $copyIndex,
        bool $shareFaculty = false,
        bool $shareRooms = false,
    ): array {
        if ($copyIndex === 0) {
            return $record;
        }

        $remapped = [];

        foreach ($record as $key => $value) {
            if ($key === 'eligible_faculty_user_ids' && is_array($value)) {
                $remapped[$key] = array_map(
                    fn (mixed $id): int => $shareFaculty ? (int) $id : $this->offsetId($id, $copyIndex),
                    $value,
                );

                continue;
            }

            if (is_array($value)) {
                $remapped[$key] = array_is_list($value)
                    ? array_map(
                        fn (mixed $item): mixed => is_array($item)
                            ? $this->remapCopy($item, $copyIndex, $shareFaculty, $shareRooms)
                            : $item,
                        $value,
                    )
                    : $this->remapCopy($value, $copyIndex, $shareFaculty, $shareRooms);

                continue;
            }

            if (in_array($key, self::RemappedIdFields, true) && $value !== null) {
                $remapped[$key] = $this->offsetId($value, $copyIndex);

                continue;
            }

            if (in_array($key, ['faculty_id', 'faculty_user_id', 'fixed_faculty_user_id'], true)
                && $value !== null) {
                $remapped[$key] = $shareFaculty ? (int) $value : $this->offsetId($value, $copyIndex);

                continue;
            }

            if (in_array($key, ['room_id', 'fixed_room_id'], true) && $value !== null) {
                $remapped[$key] = $shareRooms ? (int) $value : $this->offsetId($value, $copyIndex);

                continue;
            }

            if (is_string($value) && in_array($key, ['code', 'course_code', 'demand_key', 'name'], true)) {
                $remapped[$key] = $value.'-B'.($copyIndex + 1);

                continue;
            }

            $remapped[$key] = $value;
        }

        return $remapped;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $demands
     * @return list<array<string, mixed>>
     */
    private function reducedRooms(array $snapshot, array $demands): array
    {
        $requiredTypes = collect($demands)
            ->filter(fn (array $demand): bool => ($demand['room_required'] ?? false) === true)
            ->pluck('room_type_requirement')
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $rooms = collect($this->list($snapshot['rooms'] ?? null));
        $reducedRooms = $requiredTypes
            ->map(function (mixed $type) use ($demands, $rooms): mixed {
                $typeDemands = array_values(array_filter(
                    $demands,
                    fn (array $demand): bool => ($demand['room_type_requirement'] ?? null) === $type,
                ));

                return $rooms->first(
                    fn (array $room): bool => ($room['room_type'] ?? null) === $type
                        && $this->roomSupportsDemands($room, $typeDemands),
                );
            })
            ->filter(fn (mixed $room): bool => is_array($room))
            ->values()
            ->all();

        if (count($reducedRooms) !== 3) {
            throw new RuntimeException('The representative snapshot cannot produce the three-room reduced tier.');
        }

        return $reducedRooms;
    }

    /** @param array<string, mixed> $demand */
    private function demandFacultyReferences(array $demand): array
    {
        $references = collect($demand['eligible_faculty_user_ids'] ?? [])
            ->merge(collect($demand['faculty_load_options'] ?? [])->pluck('faculty_user_id'));

        if (($demand['fixed_faculty_user_id'] ?? null) !== null) {
            $references->push($demand['fixed_faculty_user_id']);
        }

        return $references
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $room
     * @param  list<array<string, mixed>>  $demands
     */
    private function roomSupportsDemands(array $room, array $demands): bool
    {
        $capacity = (int) ($room['capacity'] ?? 0);
        $features = collect($room['feature_keys'] ?? [])
            ->map(fn (mixed $feature): string => mb_strtoupper(trim((string) $feature)));

        return collect($demands)->every(function (array $demand) use ($capacity, $features, $room): bool {
            $fixedRoomId = $demand['fixed_room_id'] ?? null;
            $requiredFeatures = collect($demand['required_room_feature_keys'] ?? [])
                ->map(fn (mixed $feature): string => mb_strtoupper(trim((string) $feature)));

            return ($fixedRoomId === null || (int) $fixedRoomId === (int) ($room['room_id'] ?? 0))
                && $capacity >= (int) ($demand['expected_count'] ?? 0)
                && $requiredFeatures->diff($features)->isEmpty();
        });
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<int>  $facultyIds
     * @param  list<int>  $roomIds
     */
    private function blockTargetsSelectedResources(array $block, array $facultyIds, array $roomIds): bool
    {
        $facultyId = $block['faculty_user_id'] ?? $block['faculty_id'] ?? null;
        $roomId = $block['room_id'] ?? null;

        return ($facultyId === null || in_array((int) $facultyId, $facultyIds, true))
            && ($roomId === null || in_array((int) $roomId, $roomIds, true));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function filterByIds(array $snapshot, string $listKey, string $idKey, array $ids): array
    {
        return array_values(array_filter(
            $this->list($snapshot[$listKey] ?? null),
            fn (array $row): bool => in_array((int) ($row[$idKey] ?? 0), $ids, true),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function integerValues(array $rows, string $key): array
    {
        return collect($rows)
            ->pluck($key)
            ->filter(fn (mixed $value): bool => $value !== null)
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $value */
    private function doubleNestedFacultyCaps(array $value): array
    {
        $doubled = [];

        foreach ($value as $key => $item) {
            if ($key === 'max_allowed_units') {
                $doubled[$key] = $this->doubleDecimal($item);

                continue;
            }

            if (is_array($item)) {
                $doubled[$key] = array_is_list($item)
                    ? array_map(
                        fn (mixed $row): mixed => is_array($row)
                            ? $this->doubleNestedFacultyCaps($row)
                            : $row,
                        $item,
                    )
                    : $this->doubleNestedFacultyCaps($item);

                continue;
            }

            $doubled[$key] = $item;
        }

        return $doubled;
    }

    private function doubleDecimal(mixed $value): mixed
    {
        return $value === null ? null : number_format((float) $value * 2, 2, '.', '');
    }

    private function offsetId(mixed $value, int $copyIndex): int
    {
        return (int) $value + ($copyIndex * self::CopyOffset);
    }

    /** @param array<string, mixed> $snapshot */
    private function assertRepresentativeSnapshot(array $snapshot): void
    {
        if (($snapshot['contract_version'] ?? null) !== ScheduleGenerationRun::ContractVersion
            || data_get($snapshot, 'constraint_profile.key') !== ScheduleGenerationRun::QualityPolicyLexicographic
            || $this->composition($snapshot) !== [
                'demands' => 54,
                'faculty' => 12,
                'rooms' => 6,
                'time_slots' => 156,
            ]) {
            throw new RuntimeException('TAL-96B3 requires the exact accepted 54-demand representative snapshot.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /** @return list<array<string, mixed>> */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value)
            ? array_values(array_filter($value, fn (mixed $row): bool => is_array($row)))
            : [];
    }
}
