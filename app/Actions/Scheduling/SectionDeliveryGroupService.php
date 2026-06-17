<?php

namespace App\Actions\Scheduling;

use App\Models\DeliveryPattern;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SectionDeliveryGroupService
{
    public function __construct(private readonly DeliveryPatternService $deliveryPatternService) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function prepareForSave(Section $section, array $data, ?SectionDeliveryGroup $group = null, ?User $actor = null): array
    {
        $prepared = $this->normalize($section, $data, $group, $actor);

        $validator = Validator::make($prepared, [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')],
            'delivery_pattern_id' => ['required', 'integer', Rule::exists('delivery_patterns', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'modality' => ['required', 'string', Rule::in(array_keys(SectionDeliveryGroup::modalityOptions()))],
            'capacity' => ['required', 'integer', 'min:1', 'max:'.Section::MaxRescueSeats],
            'assigned_count' => ['required', 'integer', 'min:0'],
            'room_required' => ['required', 'boolean'],
            'room' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(array_keys(SectionDeliveryGroup::statusOptions()))],
        ]);

        $validator->after(function ($validator) use ($section, $group, $prepared): void {
            if ((int) $prepared['section_id'] !== (int) $section->id) {
                $validator->errors()->add('section_id', 'Delivery group must belong to the selected section.');
            }

            if ((int) $prepared['capacity'] > (int) $section->max_seats) {
                $validator->errors()->add('capacity', 'Delivery group capacity cannot exceed parent section capacity.');
            }

            if ((int) $prepared['capacity'] < (int) $prepared['assigned_count']) {
                $validator->errors()->add('capacity', 'Delivery group capacity cannot be lower than assigned count.');
            }

            if ($group instanceof SectionDeliveryGroup && (int) $prepared['capacity'] < (int) $group->assigned_count) {
                $validator->errors()->add('capacity', 'Delivery group capacity cannot be lowered below currently assigned students.');
            }

            $deliveryPattern = DeliveryPattern::query()->find($prepared['delivery_pattern_id']);

            if (! $deliveryPattern instanceof DeliveryPattern) {
                return;
            }

            if (! $deliveryPattern->is_active) {
                $validator->errors()->add('delivery_pattern_id', 'Selected delivery pattern must be active.');
            }

            if ($deliveryPattern->modality !== null && $deliveryPattern->modality !== $prepared['modality']) {
                $validator->errors()->add('modality', 'Delivery group modality must match the selected delivery pattern.');
            }

            if ((bool) $prepared['room_required'] && blank($prepared['room'])) {
                $validator->errors()->add('room', 'Room is required for this delivery group.');
            }

            if ((bool) $prepared['room_required'] && filled($prepared['room']) && ! $this->activeRoomExists((string) $prepared['room'])) {
                $validator->errors()->add('room', 'Selected room must exist in the active room catalog.');
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
        $prepared = $this->prepareForSave($section, $data, $group, $actor);

        return DB::transaction(function () use ($prepared, $group): SectionDeliveryGroup {
            if ($group instanceof SectionDeliveryGroup) {
                $group->forceFill($prepared)->save();
                $saved = $group;
            } else {
                $saved = SectionDeliveryGroup::query()->create($prepared);
            }

            $this->deliveryPatternService->markUsed($saved->deliveryPattern()->firstOrFail());

            return $saved->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(Section $section, array $data, ?SectionDeliveryGroup $group, ?User $actor): array
    {
        $modality = filled($data['modality'] ?? null) ? trim((string) $data['modality']) : $group?->modality;
        $roomRequired = SectionDeliveryGroup::modalityRequiresRoom($modality);
        $room = filled($data['room'] ?? null) ? trim((string) $data['room']) : $group?->room;

        if (! $roomRequired) {
            $room = null;
        }

        $status = filled($data['status'] ?? null)
            ? trim((string) $data['status'])
            : ($group?->status ?? SectionDeliveryGroup::StatusActive);

        return [
            ...$data,
            'section_id' => (int) $section->id,
            'delivery_pattern_id' => $this->integerValue($data['delivery_pattern_id'] ?? $group?->delivery_pattern_id),
            'name' => trim((string) ($data['name'] ?? $group?->name ?? '')),
            'modality' => $modality,
            'capacity' => $this->integerValue($data['capacity'] ?? $group?->capacity),
            'assigned_count' => $this->integerValue($data['assigned_count'] ?? $group?->assigned_count ?? 0),
            'room_required' => $roomRequired,
            'room' => $room,
            'status' => $status,
            'created_by' => $this->integerValue($data['created_by'] ?? $group?->created_by ?? $actor?->id),
            'updated_by' => $this->integerValue($data['updated_by'] ?? $actor?->id),
            'closed_at' => $status === SectionDeliveryGroup::StatusClosed
                ? ($data['closed_at'] ?? $group?->closed_at ?? now())
                : null,
        ];
    }

    private function activeRoomExists(string $roomCode): bool
    {
        return Room::query()
            ->where('code', $roomCode)
            ->where('is_active', true)
            ->exists();
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
