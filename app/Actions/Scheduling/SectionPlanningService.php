<?php

namespace App\Actions\Scheduling;

use App\Models\Section;
use App\Models\TermOffering;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SectionPlanningService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function prepareForSave(array $data, ?Section $section = null): array
    {
        $prepared = $this->normalize($data, $section);

        $validator = Validator::make($prepared, [
            'term_offering_id' => ['required', 'integer', 'exists:term_offerings,id'],
            'code' => [
                'required',
                'string',
                'max:255',
            ],
            'capacity' => ['required', 'integer', 'min:1'],
            'state' => ['required', 'string', Rule::in(array_keys(Section::stateOptions()))],
        ]);

        $validator->after(function ($validator) use ($prepared, $section): void {
            $termOffering = TermOffering::query()->find($prepared['term_offering_id']);

            if (! $termOffering instanceof TermOffering || blank($prepared['code'])) {
                return;
            }

            $duplicateExists = Section::query()
                ->where('code', $prepared['code'])
                ->when(
                    $section instanceof Section,
                    fn ($query) => $query->whereKeyNot($section->id),
                )
                ->whereHas(
                    'termOffering',
                    fn ($query) => $query->where('term_id', $termOffering->term_id),
                )
                ->exists();

            if ($duplicateExists) {
                $validator->errors()->add(
                    'code',
                    'Section source-record codes must be unique within the selected term.',
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
     * @return array<string, mixed>
     */
    private function normalize(array $data, ?Section $section): array
    {
        return [
            'term_offering_id' => $this->integerValue($data['term_offering_id'] ?? $section?->term_offering_id),
            'code' => filled($data['code'] ?? null) ? trim((string) $data['code']) : $section?->code,
            'capacity' => $this->integerValue($data['capacity'] ?? $section?->capacity),
            'state' => filled($data['state'] ?? null)
                ? trim((string) $data['state'])
                : ($section instanceof Section ? $section->state : Section::StatePlanned),
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
