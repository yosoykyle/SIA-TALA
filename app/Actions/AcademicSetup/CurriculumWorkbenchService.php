<?php

namespace App\Actions\AcademicSetup;

use App\Models\CourseComponent;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CurriculumWorkbenchService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createEntry(
        User $actor,
        CurriculumVersion $curriculumVersion,
        array $data,
    ): CurriculumEntry {
        $attributes = $this->validatedPlacement($data, requireSpecification: true);

        return DB::transaction(function () use ($actor, $curriculumVersion, $attributes): CurriculumEntry {
            $lockedCurriculum = CurriculumVersion::query()
                ->lockForUpdate()
                ->findOrFail($curriculumVersion->id);

            Gate::forUser($actor)->authorize('update', $lockedCurriculum);

            $specification = CourseSpecification::query()
                ->lockForUpdate()
                ->findOrFail($attributes['course_specification_id']);

            $this->ensureUniquePlacement(
                curriculumVersion: $lockedCurriculum,
                courseSpecification: $specification,
                yearLevel: $attributes['year_level'],
                termLabel: $attributes['term_label'],
            );

            $entry = $lockedCurriculum->entries()->create($attributes);

            activity()
                ->performedOn($entry)
                ->causedBy($actor)
                ->event('curriculum_workbench_row_created')
                ->withProperties([
                    'curriculum_version_id' => $lockedCurriculum->id,
                    'course_specification_id' => $specification->id,
                    'year_level' => $entry->year_level,
                    'term_label' => $entry->term_label,
                    'sequence' => $entry->sequence,
                ])
                ->log('Curriculum row added from Academic Readiness');

            return $entry->fresh(['courseSpecification.course']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePlacement(
        User $actor,
        CurriculumEntry $entry,
        array $data,
    ): CurriculumEntry {
        $attributes = $this->validatedPlacement($data);

        return DB::transaction(function () use ($actor, $entry, $attributes): CurriculumEntry {
            $lockedEntry = CurriculumEntry::query()
                ->with(['curriculumVersion', 'courseSpecification'])
                ->lockForUpdate()
                ->findOrFail($entry->id);

            Gate::forUser($actor)->authorize('update', $lockedEntry->curriculumVersion);

            $this->ensureUniquePlacement(
                curriculumVersion: $lockedEntry->curriculumVersion,
                courseSpecification: $lockedEntry->courseSpecification,
                yearLevel: $attributes['year_level'],
                termLabel: $attributes['term_label'],
                ignoreEntry: $lockedEntry,
            );

            $lockedEntry->fill($attributes)->save();

            activity()
                ->performedOn($lockedEntry)
                ->causedBy($actor)
                ->event('curriculum_workbench_placement_updated')
                ->withProperties([
                    'curriculum_version_id' => $lockedEntry->curriculum_version_id,
                    'course_specification_id' => $lockedEntry->course_specification_id,
                    'year_level' => $lockedEntry->year_level,
                    'term_label' => $lockedEntry->term_label,
                    'sequence' => $lockedEntry->sequence,
                    'requirement_group' => $lockedEntry->requirement_group,
                ])
                ->log('Curriculum placement updated from Academic Readiness');

            return $lockedEntry->fresh(['courseSpecification.course']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSpecification(
        User $actor,
        CurriculumEntry $entry,
        array $data,
    ): CourseSpecification {
        $attributes = $this->validatedSpecification($data);
        $components = $attributes['components'];
        unset($attributes['components']);

        return DB::transaction(function () use ($actor, $entry, $attributes, $components): CourseSpecification {
            $lockedEntry = CurriculumEntry::query()
                ->with('curriculumVersion')
                ->lockForUpdate()
                ->findOrFail($entry->id);

            Gate::forUser($actor)->authorize('update', $lockedEntry->curriculumVersion);

            $specification = CourseSpecification::query()
                ->lockForUpdate()
                ->findOrFail($lockedEntry->course_specification_id);

            Gate::forUser($actor)->authorize('update', $specification);

            $specification->fill($attributes)->save();
            $specification->components()->delete();

            foreach ($components as $component) {
                $specification->components()->create($component);
            }

            activity()
                ->performedOn($specification)
                ->causedBy($actor)
                ->event('curriculum_workbench_specification_updated')
                ->withProperties([
                    'curriculum_entry_id' => $lockedEntry->id,
                    'curriculum_version_id' => $lockedEntry->curriculum_version_id,
                    'component_count' => count($components),
                    'allowed_modalities' => $specification->allowed_modalities,
                ])
                ->log('Course Specification completed from Academic Readiness');

            return $specification->fresh(['course', 'components', 'requirements']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     course_specification_id?: int,
     *     year_level: string,
     *     term_label: string,
     *     term_type: string,
     *     sequence: int,
     *     requirement_group: string
     * }
     */
    private function validatedPlacement(array $data, bool $requireSpecification = false): array
    {
        $rules = [
            'year_level' => ['required', 'integer', 'between:1,3'],
            'term_label' => ['required', 'string', 'max:255'],
            'term_type' => ['required', Rule::in(array_keys(Term::typeOptions()))],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535'],
            'requirement_group' => ['required', Rule::in(array_keys(CurriculumEntry::requirementGroupOptions()))],
        ];

        if ($requireSpecification) {
            $rules['course_specification_id'] = ['required', 'integer', 'exists:course_specifications,id'];
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, $rules)->validate();

        $attributes = [
            'year_level' => (string) $validated['year_level'],
            'term_label' => trim((string) $validated['term_label']),
            'term_type' => (string) $validated['term_type'],
            'sequence' => (int) $validated['sequence'],
            'requirement_group' => (string) $validated['requirement_group'],
        ];

        if ($requireSpecification) {
            $attributes['course_specification_id'] = (int) $validated['course_specification_id'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     title: string,
     *     credit_units: float|int|string,
     *     grading_profile_key: string,
     *     grading_profile_version: int,
     *     scheduling_treatment: string,
     *     allowed_modalities: list<string>,
     *     same_faculty_default: bool,
     *     components: list<array{
     *         component_type: string,
     *         weekly_contact_hours: float|int|string,
     *         meeting_pattern: string,
     *         room_type_default: string|null,
     *         required_room_feature_keys: list<string>,
     *         modality_restriction: string|null,
     *         requires_consecutive_block: bool,
     *         same_faculty: bool,
     *         sequence: int
     *     }>
     * }
     */
    private function validatedSpecification(array $data): array
    {
        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'credit_units' => ['required', 'numeric', 'gt:0'],
            'grading_profile_key' => ['required', Rule::in(array_keys(CourseSpecification::gradingProfileOptions()))],
            'grading_profile_version' => ['required', 'integer', 'min:1'],
            'scheduling_treatment' => ['required', Rule::in(array_keys(CourseSpecification::schedulingTreatmentOptions()))],
            'allowed_modalities' => ['required', 'array', 'min:1'],
            'allowed_modalities.*' => ['required', Rule::in(array_keys(CourseSpecification::modalityOptions()))],
            'same_faculty_default' => ['required', 'boolean'],
            'components' => [
                'array',
                Rule::requiredIf(($data['scheduling_treatment'] ?? null) === CourseSpecification::SchedulingRecurring),
                Rule::prohibitedIf(($data['scheduling_treatment'] ?? null) === CourseSpecification::SchedulingExternallyArranged),
            ],
            'components.*.component_type' => ['required', Rule::in(array_keys(CourseComponent::typeOptions()))],
            'components.*.weekly_contact_hours' => ['required', 'numeric', 'gt:0'],
            'components.*.meeting_pattern' => ['required', Rule::in(array_keys(CourseComponent::meetingPatternOptions()))],
            'components.*.room_type_default' => ['nullable', Rule::in(array_keys(CourseComponent::roomTypeOptions()))],
            'components.*.required_room_feature_keys' => ['nullable', 'array'],
            'components.*.required_room_feature_keys.*' => ['string', 'max:255'],
            'components.*.modality_restriction' => ['nullable', Rule::in(array_keys(CourseSpecification::modalityOptions()))],
            'components.*.requires_consecutive_block' => ['required', 'boolean'],
            'components.*.same_faculty' => ['required', 'boolean'],
            'components.*.sequence' => ['required', 'integer', 'min:1', 'max:65535'],
        ])->validate();

        return [
            'title' => trim((string) $validated['title']),
            'credit_units' => $validated['credit_units'],
            'grading_profile_key' => (string) $validated['grading_profile_key'],
            'grading_profile_version' => (int) $validated['grading_profile_version'],
            'scheduling_treatment' => (string) $validated['scheduling_treatment'],
            'allowed_modalities' => array_values($validated['allowed_modalities']),
            'same_faculty_default' => (bool) $validated['same_faculty_default'],
            'components' => collect($validated['components'] ?? [])
                ->map(fn (array $component): array => [
                    'component_type' => (string) $component['component_type'],
                    'weekly_contact_hours' => $component['weekly_contact_hours'],
                    'meeting_pattern' => (string) $component['meeting_pattern'],
                    'room_type_default' => filled($component['room_type_default'] ?? null)
                        ? (string) $component['room_type_default']
                        : null,
                    'required_room_feature_keys' => array_values($component['required_room_feature_keys'] ?? []),
                    'modality_restriction' => filled($component['modality_restriction'] ?? null)
                        ? (string) $component['modality_restriction']
                        : null,
                    'requires_consecutive_block' => (bool) $component['requires_consecutive_block'],
                    'same_faculty' => (bool) $component['same_faculty'],
                    'sequence' => (int) $component['sequence'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function ensureUniquePlacement(
        CurriculumVersion $curriculumVersion,
        CourseSpecification $courseSpecification,
        string $yearLevel,
        string $termLabel,
        ?CurriculumEntry $ignoreEntry = null,
    ): void {
        $duplicate = CurriculumEntry::query()
            ->whereBelongsTo($curriculumVersion)
            ->whereBelongsTo($courseSpecification)
            ->where('year_level', $yearLevel)
            ->where('term_label', $termLabel)
            ->when(
                $ignoreEntry instanceof CurriculumEntry,
                fn ($query) => $query->whereKeyNot($ignoreEntry->id),
            )
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'term_label' => 'This Course Specification already exists in that year level and term.',
            ]);
        }
    }
}
