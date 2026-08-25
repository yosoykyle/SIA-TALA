<?php

namespace App\Actions\Enrollment;

use App\Models\CurriculumEntry;
use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\EnrollmentGateResult;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class StudentUnitLoadService
{
    /** @return array<string, string> */
    public function positionOptions(Enrollment $enrollment): array
    {
        $enrollment->loadMissing('studentProfile');
        $entries = CurriculumEntry::query()
            ->with('courseSpecification')
            ->where('curriculum_version_id', $enrollment->studentProfile?->curriculum_version_id)
            ->orderBy('year_level')
            ->orderBy('term_label')
            ->orderBy('sequence')
            ->get();

        return $entries
            ->groupBy(fn (CurriculumEntry $entry): string => $this->positionKey(
                (string) $entry->year_level,
                (string) $entry->term_label,
                (string) $entry->term_type,
            ))
            ->map(function (EloquentCollection $positionEntries): string {
                $entry = $positionEntries->firstOrFail();
                $units = (float) $positionEntries->sum(
                    fn (CurriculumEntry $curriculumEntry): float => (float) $curriculumEntry->courseSpecification?->credit_units,
                );

                return $entry->year_level.' — '.$entry->term_label.' ('.$this->units($units).' units)';
            })
            ->all();
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @return array<string, mixed>
     */
    public function snapshotForSections(
        Enrollment $enrollment,
        Collection $sections,
        ?string $confirmedPosition = null,
    ): array {
        $enrollment->loadMissing(['studentProfile', 'term']);
        $profile = $enrollment->studentProfile;
        $term = $enrollment->term;

        if (! $term instanceof Term || $sections->isEmpty()) {
            $this->unavailable();
        }

        $sections->each(fn (Section $section) => $section->loadMissing(
            'termOffering.curriculumEntry.courseSpecification',
        ));
        $offerings = $sections
            ->map(fn (Section $section): ?TermOffering => $section->termOffering)
            ->filter(fn (mixed $offering): bool => $offering instanceof TermOffering)
            ->values();

        $curriculumIds = $offerings
            ->pluck('curriculumEntry.curriculum_version_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $curriculumVersionId = $profile instanceof StudentProfile
            ? $profile->curriculum_version_id
            : $curriculumIds->first();

        if ($offerings->count() !== $sections->count()
            || $curriculumIds->count() !== 1
            || $curriculumVersionId === null
            || ($profile instanceof StudentProfile && (int) $profile->curriculum_version_id !== (int) $curriculumVersionId)
            || $offerings->contains(fn (TermOffering $offering): bool => (int) $offering->term_id !== (int) $term->id
                || (int) $offering->curriculumEntry->curriculum_version_id !== (int) $curriculumVersionId
                || $offering->curriculumEntry->courseSpecification === null)) {
            $this->unavailable('Every selected class must belong to the Student’s assigned Curriculum Version and exact Term.');
        }

        $position = $this->resolvePosition($offerings, $confirmedPosition);
        $entries = CurriculumEntry::query()
            ->with('courseSpecification')
            ->where('curriculum_version_id', $curriculumVersionId)
            ->where('year_level', $position['year_level'])
            ->where('term_label', $position['term_label'])
            ->where('term_type', $position['term_type'])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        $normalTotal = (float) $entries->sum(
            fn (CurriculumEntry $entry): float => (float) $entry->courseSpecification?->credit_units,
        );

        if ($entries->isEmpty() || $normalTotal <= 0) {
            $this->unavailable();
        }

        $requestedTotal = (float) $offerings->sum(
            fn (TermOffering $offering): float => (float) $offering->curriculumEntry?->courseSpecification?->credit_units,
        );
        $curriculumEntryIds = $entries->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $termOfferingIds = $offerings->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $sectionIds = $sections->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $selection = [
            'term_id' => (int) $term->id,
            'curriculum_version_id' => (int) $curriculumVersionId,
            'year_level' => $position['year_level'],
            'term_label' => $position['term_label'],
            'term_type' => $position['term_type'],
            'curriculum_entry_ids' => $curriculumEntryIds,
            'term_offering_ids' => $termOfferingIds,
            'section_ids' => $sectionIds,
            'normal_total' => $this->units($normalTotal),
            'requested_total' => $this->units($requestedTotal),
        ];

        return [
            ...$selection,
            'selection_hash' => hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR)),
            'requires_graduating_overload' => $requestedTotal > $normalTotal,
            'unit_load_passes' => $requestedTotal <= $normalTotal,
        ];
    }

    /** @return array<string, mixed> */
    public function currentProposalLoad(
        Enrollment $enrollment,
        RegistrationProposalVersion $proposal,
        bool $lockForUpdate = false,
    ): array {
        $enrollment->loadMissing(['studentProfile', 'term']);
        $proposal->loadMissing(['items.section.termOffering.curriculumEntry.courseSpecification']);
        $snapshot = data_get($proposal->source_snapshot, 'unit_load');

        if (! is_array($snapshot)
            || (int) $proposal->enrollment_id !== (int) $enrollment->id
            || (int) $enrollment->current_proposal_version_id !== (int) $proposal->id
            || (int) ($snapshot['term_id'] ?? 0) !== (int) $enrollment->term_id
            || (int) ($snapshot['curriculum_version_id'] ?? 0) !== (int) $proposal->curriculum_version_id) {
            $this->unavailable('Prepare a current proposal from the exact curriculum and Term.');
        }

        $sections = $proposal->items
            ->pluck('section')
            ->filter(fn (mixed $section): bool => $section instanceof Section)
            ->values();
        if ($sections->count() !== $proposal->items->count()) {
            $this->unavailable('One or more proposal classes no longer exist.');
        }

        $recomputed = $this->snapshotForSections(
            $enrollment,
            $sections,
            $this->positionKey(
                (string) ($snapshot['year_level'] ?? ''),
                (string) ($snapshot['term_label'] ?? ''),
                (string) ($snapshot['term_type'] ?? ''),
            ),
        );

        foreach ([
            'year_level', 'term_label', 'term_type', 'curriculum_entry_ids', 'term_offering_ids',
            'section_ids', 'normal_total', 'requested_total', 'selection_hash',
        ] as $key) {
            if (($snapshot[$key] ?? null) !== $recomputed[$key]) {
                $this->unavailable('The proposal load source changed. Prepare a successor proposal.');
            }
        }

        if ($lockForUpdate) {
            EnrollmentException::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('exception_type', EnrollmentException::TypeGraduatingOverload)
                ->lockForUpdate()
                ->get();
        }

        return $recomputed;
    }

    /** @return array<string, mixed> */
    public function assertProposalPermitted(
        Enrollment $enrollment,
        RegistrationProposalVersion $proposal,
        bool $lockForUpdate = false,
    ): array {
        $snapshot = $this->currentProposalLoad($enrollment, $proposal, $lockForUpdate);

        if (! $snapshot['requires_graduating_overload']) {
            return [...$snapshot, 'unit_load_passes' => true];
        }

        $profile = $enrollment->studentProfile;
        $query = EnrollmentException::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('student_profile_id', $enrollment->student_profile_id)
            ->where('term_id', $enrollment->term_id)
            ->where('exception_type', EnrollmentException::TypeGraduatingOverload)
            ->where('state', EnrollmentException::StateActive)
            ->where('scope_key', 'like', 'graduating_overload:proposal:'.$proposal->id.':%')
            ->where('approved_values->proposal_content_hash', $proposal->content_hash)
            ->where('approved_values->selection_hash', $snapshot['selection_hash'])
            ->where(fn ($builder) => $builder->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('approved_at')
            ->latest('id');
        $authority = $lockForUpdate ? $query->lockForUpdate()->first() : $query->first();

        if (! $profile instanceof StudentProfile
            || $profile->academic_standing !== StudentProfile::StandingGraduationCandidate
            || ! $authority instanceof EnrollmentException) {
            throw ValidationException::withMessages([
                'unit_load' => 'Record the matching external graduating-overload authority for this exact proposal before continuing.',
            ]);
        }

        return [...$snapshot, 'unit_load_passes' => true, 'authority_id' => (int) $authority->id];
    }

    /**
     * Fail-closed compatibility projection for retained legacy callers.
     *
     * @return array<string, mixed>
     */
    public function evaluate(
        Enrollment $enrollment,
        float $requestedTotal,
        ?float $unusedConfiguredCap = null,
        ?string $yearLevel = null,
    ): array {
        $enrollment->loadMissing(['studentProfile', 'term']);
        $groups = CurriculumEntry::query()
            ->where('curriculum_version_id', $enrollment->studentProfile?->curriculum_version_id)
            ->when($yearLevel, fn ($query) => $query->where('year_level', $yearLevel))
            ->where('term_type', $enrollment->term?->type)
            ->get(['year_level', 'term_label', 'term_type'])
            ->unique(fn (CurriculumEntry $entry): string => $this->positionKey(
                $entry->year_level,
                $entry->term_label,
                $entry->term_type,
            ));
        $otherFailedGates = EnrollmentGateResult::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('result', EnrollmentGateResult::ResultFailed)
            ->where('gate_type', '!=', EnrollmentGateResult::GateAcademicProgression)
            ->pluck('gate_type')
            ->values()
            ->all();

        if ($groups->count() !== 1) {
            return [
                'enrollment_id' => (int) $enrollment->id,
                'normal_load' => null,
                'requested_total' => $this->units($requestedTotal),
                'configured_cap' => null,
                'approved_excess' => '0.00',
                'approved_limit' => null,
                'requires_exception' => true,
                'has_active_exception' => false,
                'unit_load_passes' => false,
                'other_failed_gates' => $otherFailedGates,
                'all_gates_pass' => false,
                'blocker' => 'Curriculum term load unavailable',
            ];
        }

        $group = $groups->sole();
        $normal = (float) CurriculumEntry::query()
            ->where('curriculum_version_id', $enrollment->studentProfile?->curriculum_version_id)
            ->where('year_level', $group->year_level)
            ->where('term_label', $group->term_label)
            ->where('term_type', $group->term_type)
            ->join('course_specifications', 'curriculum_entries.course_specification_id', '=', 'course_specifications.id')
            ->sum('course_specifications.credit_units');
        $passes = $normal > 0 && $requestedTotal <= $normal;

        return [
            'enrollment_id' => (int) $enrollment->id,
            'normal_load' => $normal > 0 ? $this->units($normal) : null,
            'requested_total' => $this->units($requestedTotal),
            'configured_cap' => null,
            'approved_excess' => '0.00',
            'approved_limit' => $normal > 0 ? $this->units($normal) : null,
            'requires_exception' => $requestedTotal > $normal,
            'has_active_exception' => false,
            'unit_load_passes' => $passes,
            'other_failed_gates' => $otherFailedGates,
            'all_gates_pass' => $passes && $otherFailedGates === [],
            'blocker' => $passes ? null : 'Curriculum term load unavailable or graduating-overload authority required',
        ];
    }

    /**
     * @param  Collection<int, TermOffering>  $offerings
     * @return array{year_level:string,term_label:string,term_type:string}
     */
    private function resolvePosition(Collection $offerings, ?string $confirmedPosition): array
    {
        $regularPositions = $offerings
            ->filter(fn (TermOffering $offering): bool => $offering->category === TermOffering::CategoryRegular)
            ->map(fn (TermOffering $offering): array => $this->entryPosition($offering->curriculumEntry))
            ->unique(fn (array $position): string => $this->positionKey(...array_values($position)))
            ->values();
        $selectedPositions = $offerings
            ->map(fn (TermOffering $offering): array => $this->entryPosition($offering->curriculumEntry))
            ->unique(fn (array $position): string => $this->positionKey(...array_values($position)))
            ->values();

        if (filled($confirmedPosition)) {
            $position = $this->parsePositionKey($confirmedPosition);
            if ($regularPositions->isNotEmpty()
                && $regularPositions->contains(fn (array $regular): bool => $regular !== $position)) {
                $this->unavailable('The confirmed curriculum position conflicts with an ordinary selected class.');
            }

            return $position;
        }

        if ($regularPositions->count() === 1) {
            return $regularPositions->sole();
        }

        if ($selectedPositions->count() === 1) {
            return $selectedPositions->sole();
        }

        $this->unavailable('Registrar must confirm one exact position from the assigned Curriculum Version.');
    }

    /** @return array{year_level:string,term_label:string,term_type:string} */
    private function entryPosition(?CurriculumEntry $entry): array
    {
        if (! $entry instanceof CurriculumEntry
            || blank($entry->year_level) || blank($entry->term_label) || blank($entry->term_type)) {
            $this->unavailable();
        }

        return [
            'year_level' => (string) $entry->year_level,
            'term_label' => (string) $entry->term_label,
            'term_type' => (string) $entry->term_type,
        ];
    }

    /** @return array{year_level:string,term_label:string,term_type:string} */
    private function parsePositionKey(string $key): array
    {
        $parts = explode('|', $key);
        if (count($parts) !== 3 || collect($parts)->contains(fn (string $part): bool => blank($part))) {
            $this->unavailable();
        }

        return ['year_level' => $parts[0], 'term_label' => $parts[1], 'term_type' => $parts[2]];
    }

    private function positionKey(string $yearLevel, string $termLabel, string $termType): string
    {
        return implode('|', [$yearLevel, $termLabel, $termType]);
    }

    private function units(float $units): string
    {
        return number_format($units, 2, '.', '');
    }

    private function unavailable(?string $detail = null): never
    {
        throw ValidationException::withMessages([
            'unit_load' => 'Curriculum term load unavailable'.($detail !== null ? ". {$detail}" : '.'),
        ]);
    }
}
