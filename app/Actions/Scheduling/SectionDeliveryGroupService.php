<?php

namespace App\Actions\Scheduling;

use App\Models\PublishedTimetableMeeting;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SectionDeliveryGroupService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function prepareForSave(Section $section, array $data, ?SectionDeliveryGroup $group = null, ?User $actor = null): array
    {
        if ($group && (int) $group->section_id !== (int) $section->id) {
            throw ValidationException::withMessages(['name' => 'This delivery group belongs to another source section.']);
        }
        if (PublishedTimetableMeeting::query()->where('section_id', $section->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'Published class delivery inputs require an impact-checked timetable revision; they cannot be changed here.']);
        }
        $prepared = $this->normalize($section, $data, $group);

        $validator = Validator::make($prepared, [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('section_delivery_groups', 'name')
                    ->where('section_id', $section->id)
                    ->ignore($group?->id),
            ],
            'expected_count' => ['required', 'integer', 'min:0'],
            'modality' => ['required', 'string', Rule::in(array_keys(SectionDeliveryGroup::modalityOptions()))],
            'delivery_override' => ['nullable', 'array'],
            'state' => ['required', 'string', Rule::in(array_keys(SectionDeliveryGroup::stateOptions()))],
        ]);

        $validator->after(function ($validator) use ($section, $prepared): void {
            if ((int) $prepared['section_id'] !== (int) $section->id) {
                $validator->errors()->add('section_id', 'Delivery group must belong to the selected section.');
            }

            if ((int) $prepared['expected_count'] > (int) $section->capacity) {
                $validator->errors()->add('expected_count', 'Delivery-group expected count cannot exceed parent section capacity.');
            }

            $specification = $section->courseRevision ?? $section->termOffering?->courseSpecification();
            $allowedModalities = $specification?->getAttribute('allowed_modalities');

            if (is_array($allowedModalities)
                && ! in_array($prepared['modality'], $allowedModalities, true)) {
                $validator->errors()->add(
                    'modality',
                    'The selected modality is not allowed by the parent Course Specification.',
                );
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Section $section, array $data, ?SectionDeliveryGroup $group = null, ?User $actor = null): SectionDeliveryGroup
    {
        return DB::transaction(function () use ($section, $data, $group, $actor): SectionDeliveryGroup {
            $termId = $section->calendarPackage()->value('term_id') ?? $section->termOffering()->value('term_id');
            if (! $termId || ! Term::query()->whereKey($termId)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['name' => 'The source section requires an existing exact-Term calendar or offering before delivery inputs can be changed.']);
            }
            $section = Section::query()->lockForUpdate()->findOrFail($section->id);
            if ($actor) {
                Gate::forUser($actor)->authorize('update', $section);
            }
            $group = $group ? SectionDeliveryGroup::query()->lockForUpdate()->findOrFail($group->id) : null;
            $prepared = $this->prepareForSave($section, $data, $group, $actor);
            if ($group instanceof SectionDeliveryGroup) {
                $group->forceFill($prepared)->save();
                $saved = $group;
            } else {
                $saved = SectionDeliveryGroup::query()->create($prepared);
            }

            return $saved->refresh();
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(Section $section, array $data, ?SectionDeliveryGroup $group): array
    {
        $modality = filled($data['modality'] ?? null) ? trim((string) $data['modality']) : $group?->modality;
        $deliveryOverride = $data['delivery_override'] ?? $group?->delivery_override;
        $name = filled($data['name'] ?? null)
            ? trim((string) $data['name'])
            : ($group instanceof SectionDeliveryGroup ? $group->name : '');
        $state = filled($data['state'] ?? null)
            ? trim((string) $data['state'])
            : ($group instanceof SectionDeliveryGroup ? $group->state : SectionDeliveryGroup::StatePlanned);

        return [
            'section_id' => (int) $section->id,
            'name' => $name,
            'expected_count' => $this->integerValue($data['expected_count'] ?? $group?->expected_count),
            'modality' => $modality,
            'delivery_override' => is_array($deliveryOverride) && $deliveryOverride !== [] ? $deliveryOverride : null,
            'state' => $state,
        ];
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
