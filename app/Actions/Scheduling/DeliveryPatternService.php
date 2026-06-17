<?php

namespace App\Actions\Scheduling;

use App\Models\DeliveryPattern;
use App\Models\SectionDeliveryGroup;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryPatternService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function prepareForSave(array $data, ?DeliveryPattern $deliveryPattern = null, ?User $actor = null): array
    {
        $prepared = $this->normalize($data, $deliveryPattern, $actor);

        $validator = Validator::make($prepared, [
            'code' => ['required', 'string', 'max:50'],
            'version' => ['required', 'integer', 'min:1', 'max:999'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'modality' => ['nullable', 'string', Rule::in(array_keys(DeliveryPattern::modalityOptions()))],
            'allowed_days' => ['nullable', 'array'],
            'allowed_days.*' => ['integer', Rule::in(array_keys(DeliveryPattern::dayOptions()))],
            'subject_routing' => ['required', 'string', Rule::in(array_keys(DeliveryPattern::subjectRoutingOptions()))],
            'enforcement_level' => ['required', 'string', Rule::in(array_keys(DeliveryPattern::enforcementLevelOptions()))],
            'default_room_required' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($deliveryPattern, $prepared): void {
            if ($this->duplicateCodeVersionExists($prepared, $deliveryPattern)) {
                $validator->errors()->add('version', 'A delivery pattern already exists for this code and version.');
            }

            if (! SectionDeliveryGroup::modalityRequiresRoom($prepared['modality'] ?? null) && (bool) ($prepared['default_room_required'] ?? false)) {
                $validator->errors()->add('default_room_required', 'Only on-site and blended delivery patterns may require a room by default.');
            }

            if ($deliveryPattern instanceof DeliveryPattern && $deliveryPattern->isRuleLocked() && $this->hasRuleChanges($deliveryPattern, $prepared)) {
                $validator->errors()->add('code', 'This delivery pattern version is already used and frozen. Clone a new version to change delivery rules.');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $prepared;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function cloneNewVersion(DeliveryPattern $source, User $actor, array $overrides = []): DeliveryPattern
    {
        $nextVersion = ((int) DeliveryPattern::query()
            ->where('code', $source->code)
            ->max('version')) + 1;

        $payload = [
            'code' => $source->code,
            'version' => $nextVersion,
            'name' => "{$source->name} v{$nextVersion}",
            'description' => $source->description,
            'modality' => $source->modality,
            'allowed_days' => $source->allowed_days,
            'subject_routing' => $source->subject_routing,
            'enforcement_level' => $source->enforcement_level,
            'default_room_required' => $source->default_room_required,
            'is_active' => true,
            ...$overrides,
            'cloned_from_id' => $source->id,
            'created_by' => $actor->id,
        ];

        return DeliveryPattern::query()->create($this->prepareForSave($payload, null, $actor));
    }

    public function markUsed(DeliveryPattern $deliveryPattern): void
    {
        if ($deliveryPattern->is_frozen && $deliveryPattern->used_at !== null) {
            return;
        }

        $deliveryPattern->forceFill([
            'is_frozen' => true,
            'used_at' => $deliveryPattern->used_at ?? now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?DeliveryPattern $deliveryPattern, ?User $actor): array
    {
        $allowedDays = collect($data['allowed_days'] ?? $deliveryPattern?->allowed_days ?? [])
            ->filter(fn (mixed $day): bool => $day !== null && $day !== '')
            ->map(fn (mixed $day): int => (int) $day)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $modality = filled($data['modality'] ?? null) ? trim((string) $data['modality']) : $deliveryPattern?->modality;

        return [
            ...$data,
            'code' => strtoupper(trim((string) ($data['code'] ?? $deliveryPattern?->code ?? ''))),
            'version' => (int) ($data['version'] ?? $deliveryPattern?->version ?? 1),
            'name' => trim((string) ($data['name'] ?? $deliveryPattern?->name ?? '')),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'modality' => $modality,
            'allowed_days' => $allowedDays === [] ? null : $allowedDays,
            'subject_routing' => filled($data['subject_routing'] ?? null)
                ? trim((string) $data['subject_routing'])
                : ($deliveryPattern?->subject_routing ?? DeliveryPattern::SubjectRoutingSameSubjectSet),
            'enforcement_level' => filled($data['enforcement_level'] ?? null)
                ? trim((string) $data['enforcement_level'])
                : ($deliveryPattern?->enforcement_level ?? DeliveryPattern::EnforcementStrict),
            'default_room_required' => filter_var(
                $data['default_room_required'] ?? $deliveryPattern?->default_room_required ?? false,
                FILTER_VALIDATE_BOOLEAN,
            ),
            'is_active' => filter_var($data['is_active'] ?? $deliveryPattern?->is_active ?? true, FILTER_VALIDATE_BOOLEAN),
            'is_frozen' => (bool) ($data['is_frozen'] ?? $deliveryPattern?->is_frozen ?? false),
            'used_at' => $data['used_at'] ?? $deliveryPattern?->used_at,
            'cloned_from_id' => $this->integerValue($data['cloned_from_id'] ?? $deliveryPattern?->cloned_from_id),
            'created_by' => $this->integerValue($data['created_by'] ?? $deliveryPattern?->created_by ?? $actor?->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private function duplicateCodeVersionExists(array $prepared, ?DeliveryPattern $deliveryPattern): bool
    {
        return DeliveryPattern::query()
            ->where('code', $prepared['code'])
            ->where('version', $prepared['version'])
            ->when($deliveryPattern instanceof DeliveryPattern, fn ($query) => $query->whereKeyNot($deliveryPattern->id))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $prepared
     */
    private function hasRuleChanges(DeliveryPattern $deliveryPattern, array $prepared): bool
    {
        foreach (['code', 'version', 'name', 'modality', 'allowed_days', 'subject_routing', 'enforcement_level', 'default_room_required'] as $field) {
            if ($deliveryPattern->{$field} != $prepared[$field]) {
                return true;
            }
        }

        return false;
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
