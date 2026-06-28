<?php

namespace App\Actions\Registrar;

use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BuildTermOfferings
{
    /**
     * @return Collection<int, CurriculumEntry>
     */
    public function preview(Term $term, Program $program, CurriculumVersion $curriculumVersion, string $yearLevel): Collection
    {
        $this->validateScope($term, $program, $curriculumVersion);

        return CurriculumEntry::query()
            ->whereBelongsTo($curriculumVersion)
            ->where('year_level', $yearLevel)
            ->where('term_type', $term->type)
            ->whereHas('courseSpecification', fn ($query) => $query->where('state', CourseSpecification::StateActive))
            ->with([
                'courseSpecification.course',
                'courseSpecification.components',
            ])
            ->orderBy('sequence')
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int, skipped: int, blocked: int, messages: list<string>}
     */
    public function regular(
        User $actor,
        Term $term,
        Program $program,
        CurriculumVersion $curriculumVersion,
        string $yearLevel,
        array $rows,
    ): array {
        Gate::forUser($actor)->authorize('create', TermOffering::class);
        $eligibleEntries = $this->preview($term, $program, $curriculumVersion, $yearLevel)->keyBy('id');

        return DB::transaction(function () use ($term, $rows, $eligibleEntries): array {
            $summary = $this->emptySummary();

            foreach ($rows as $rowIndex => $row) {
                if (! ($row['include'] ?? true)) {
                    $summary['skipped']++;

                    continue;
                }

                $entryId = filter_var($row['curriculum_entry_id'] ?? null, FILTER_VALIDATE_INT);
                $entry = $entryId === false ? null : $eligibleEntries->get($entryId);

                if (! $entry instanceof CurriculumEntry) {
                    throw ValidationException::withMessages([
                        "rows.{$rowIndex}.curriculum_entry_id" => 'The curriculum entry is not eligible for the selected active curriculum, year level, and term type.',
                    ]);
                }

                $existing = TermOffering::query()
                    ->whereBelongsTo($term)
                    ->whereBelongsTo($entry)
                    ->where('delivery_variant', TermOffering::ArrangementNormalClass)
                    ->lockForUpdate()
                    ->first();

                if ($existing?->state === TermOffering::StateScheduled || $existing?->state === TermOffering::StateCancelled) {
                    $summary['blocked']++;
                    $summary['messages'][] = "{$this->entryLabel($entry)} is {$existing->state} and was not changed.";

                    continue;
                }

                $attributes = $this->validatedOfferingAttributes($entry, $row, TermOffering::CategoryRegular, TermOffering::ArrangementNormalClass);
                $offering = $existing ?? new TermOffering([
                    'term_id' => $term->id,
                    'curriculum_entry_id' => $entry->id,
                ]);
                $wasNew = ! $offering->exists;
                $offering->fill($attributes);
                $offering->save();

                $changed = $wasNew || $offering->wasChanged();
                $changed = $this->persistSections($term, $offering, $row['sections'] ?? [], $rowIndex) || $changed;

                if ($wasNew) {
                    $summary['created']++;
                } elseif ($changed) {
                    $summary['updated']++;
                } else {
                    $summary['skipped']++;
                }
            }

            return $summary;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{created: int, updated: int, skipped: int, blocked: int, messages: list<string>}
     */
    public function special(User $actor, Term $term, CurriculumEntry $entry, array $data): array
    {
        Gate::forUser($actor)->authorize('create', TermOffering::class);
        $curriculumVersion = $entry->getRelationValue('curriculumVersion');

        if (! $curriculumVersion instanceof CurriculumVersion) {
            throw ValidationException::withMessages(['curriculum_entry_id' => 'The curriculum entry does not have a valid curriculum scope.']);
        }

        $program = $curriculumVersion->getRelationValue('program');

        if (! $program instanceof Program) {
            throw ValidationException::withMessages(['curriculum_entry_id' => 'The curriculum entry does not have a valid curriculum scope.']);
        }

        $this->validateScope($term, $program, $curriculumVersion);

        if ($entry->term_type !== $term->type) {
            throw ValidationException::withMessages(['curriculum_entry_id' => 'The curriculum entry does not match the target term type.']);
        }

        $arrangement = (string) ($data['delivery_variant'] ?? '');

        if (! in_array($arrangement, [TermOffering::ArrangementNormalClass, TermOffering::ArrangementTutorial], true)) {
            throw ValidationException::withMessages(['delivery_variant' => 'Special offerings must use Normal Class or Tutorial delivery.']);
        }

        if (blank($data['special_reason'] ?? null)) {
            throw ValidationException::withMessages(['special_reason' => 'An approved Special Offering reason is required.']);
        }

        return DB::transaction(function () use ($term, $entry, $data, $arrangement): array {
            $existing = TermOffering::query()
                ->whereBelongsTo($term)
                ->whereBelongsTo($entry)
                ->where('delivery_variant', $arrangement)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof TermOffering) {
                return [
                    'created' => 0,
                    'updated' => 0,
                    'skipped' => 0,
                    'blocked' => 1,
                    'messages' => ['An offering already occupies this curriculum entry and delivery arrangement.'],
                ];
            }

            $offering = TermOffering::query()->create([
                'term_id' => $term->id,
                'curriculum_entry_id' => $entry->id,
                ...$this->validatedOfferingAttributes($entry, $data, TermOffering::CategorySpecial, $arrangement),
            ]);
            $this->persistSections($term, $offering, $data['sections'] ?? [], 0);

            return [
                'created' => 1,
                'updated' => 0,
                'skipped' => 0,
                'blocked' => 0,
                'messages' => [],
            ];
        }, 3);
    }

    private function validateScope(Term $term, Program $program, CurriculumVersion $curriculumVersion): void
    {
        if ($curriculumVersion->state !== CurriculumVersion::StateActive) {
            throw ValidationException::withMessages(['curriculum_version_id' => 'Only an active curriculum version can source term offerings.']);
        }

        if ((int) $curriculumVersion->program_id !== (int) $program->id) {
            throw ValidationException::withMessages(['program_id' => 'The curriculum version does not belong to the selected program.']);
        }

        if (blank($term->type)) {
            throw ValidationException::withMessages(['term_id' => 'The target term must have a term type.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedOfferingAttributes(CurriculumEntry $entry, array $data, string $category, string $arrangement): array
    {
        $expectedCount = filter_var($data['expected_count'] ?? null, FILTER_VALIDATE_INT);

        if ($expectedCount === false || $expectedCount < 0) {
            throw ValidationException::withMessages(['expected_count' => 'Expected count must be a non-negative integer.']);
        }

        $modality = (string) ($data['modality'] ?? '');
        $specification = $entry->getRelationValue('courseSpecification');
        $allowedModalities = $specification instanceof CourseSpecification
            ? $specification->getAttribute('allowed_modalities')
            : null;

        if (! $specification instanceof CourseSpecification
            || $specification->getAttribute('state') !== CourseSpecification::StateActive
            || ! is_array($allowedModalities)
            || ! in_array($modality, $allowedModalities, true)) {
            throw ValidationException::withMessages(['modality' => 'The modality is not allowed by the active Course Specification.']);
        }

        return [
            'category' => $category,
            'special_reason' => $category === TermOffering::CategorySpecial ? $data['special_reason'] : null,
            'delivery_variant' => $arrangement,
            'modality' => $modality,
            'expected_count' => $expectedCount,
            'room_type_override' => $data['room_type_override'] ?? null,
            'same_faculty_override' => $data['same_faculty_override'] ?? null,
            'state' => TermOffering::StatePendingScheduling,
        ];
    }

    private function persistSections(Term $term, TermOffering $offering, mixed $sectionRows, int $rowIndex): bool
    {
        if (! is_array($sectionRows) || $sectionRows === []) {
            throw ValidationException::withMessages(["rows.{$rowIndex}.sections" => 'At least one planned section is required.']);
        }

        $changed = false;

        foreach (array_values($sectionRows) as $sectionIndex => $sectionRow) {
            if (! is_array($sectionRow)) {
                throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}" => 'The section row is invalid.']);
            }

            $code = trim((string) ($sectionRow['code'] ?? ''));
            $capacity = filter_var($sectionRow['capacity'] ?? null, FILTER_VALIDATE_INT);

            if ($code === '') {
                throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}.code" => 'A Registrar-confirmed section code is required.']);
            }

            if ($capacity === false || $capacity <= 0) {
                throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}.capacity" => 'Section capacity must be a positive integer.']);
            }

            $codeUsedInTerm = Section::query()
                ->where('code', $code)
                ->where('term_offering_id', '!=', $offering->id)
                ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
                ->exists();

            if ($codeUsedInTerm) {
                throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}.code" => 'The section code is already in use for the selected term.']);
            }

            $section = Section::query()->firstOrNew([
                'term_offering_id' => $offering->id,
                'code' => $code,
            ]);
            $sectionWasNew = ! $section->exists;
            $section->fill(['capacity' => $capacity, 'state' => Section::StatePlanned]);
            $section->save();
            $changed = $sectionWasNew || $section->wasChanged() || $changed;

            $groupRows = $sectionRow['delivery_groups'] ?? [];

            if (! is_array($groupRows) || $groupRows === []) {
                throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}.delivery_groups" => 'At least one planned delivery group is required.']);
            }

            foreach (array_values($groupRows) as $groupIndex => $groupRow) {
                if (! is_array($groupRow)) {
                    throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}.delivery_groups.{$groupIndex}" => 'The delivery group row is invalid.']);
                }

                $name = trim((string) ($groupRow['name'] ?? ''));
                $groupExpected = filter_var($groupRow['expected_count'] ?? null, FILTER_VALIDATE_INT);
                $groupModality = (string) ($groupRow['modality'] ?? $offering->modality);

                if ($name === '') {
                    throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}.delivery_groups.{$groupIndex}.name" => 'A delivery group name is required.']);
                }

                if ($groupExpected === false || $groupExpected < 0 || $groupExpected > $capacity) {
                    throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}.delivery_groups.{$groupIndex}.expected_count" => 'Delivery-group expected count must be non-negative and cannot exceed section capacity.']);
                }

                $allowedModalities = $offering->courseSpecification()?->getAttribute('allowed_modalities');

                if (! is_array($allowedModalities) || ! in_array($groupModality, $allowedModalities, true)) {
                    throw ValidationException::withMessages(["rows.{$rowIndex}.sections.{$sectionIndex}.delivery_groups.{$groupIndex}.modality" => 'The delivery-group modality is not allowed by the Course Specification.']);
                }

                $group = SectionDeliveryGroup::query()->firstOrNew([
                    'section_id' => $section->id,
                    'name' => $name,
                ]);
                $groupWasNew = ! $group->exists;
                $group->fill([
                    'expected_count' => $groupExpected,
                    'modality' => $groupModality,
                    'delivery_override' => $groupRow['delivery_override'] ?? null,
                    'state' => SectionDeliveryGroup::StatePlanned,
                ]);
                $group->save();
                $changed = $groupWasNew || $group->wasChanged() || $changed;
            }
        }

        return $changed;
    }

    /**
     * @return array{created: int, updated: int, skipped: int, blocked: int, messages: list<string>}
     */
    private function emptySummary(): array
    {
        return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'blocked' => 0, 'messages' => []];
    }

    private function entryLabel(CurriculumEntry $entry): string
    {
        $specification = $entry->getRelationValue('courseSpecification');
        $course = $specification instanceof CourseSpecification ? $specification->getRelationValue('course') : null;
        $code = $course?->getAttribute('code');

        return is_string($code) ? $code : "Curriculum entry {$entry->id}";
    }
}
