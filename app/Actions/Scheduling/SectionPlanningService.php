<?php

namespace App\Actions\Scheduling;

use App\Models\Curriculum;
use App\Models\Room;
use App\Models\Section;
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
            'term_id' => ['required', 'integer', 'exists:terms,id'],
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'curriculum_id' => ['required', 'integer', 'exists:curriculums,id'],
            'year_level' => ['required', 'string', Rule::in(array_keys(Section::yearLevelOptions()))],
            'curriculum_period' => ['required', 'string', Rule::in(array_keys(Section::curriculumPeriodOptions()))],
            'name' => ['required', 'string', 'max:255'],
            'modality' => ['required', 'string', Rule::in(Section::modalityValues())],
            'room' => ['nullable', 'string', 'max:255'],
            'max_seats' => ['required', 'integer', 'min:1', 'max:'.Section::MaxRescueSeats],
            'enrolled_count' => ['required', 'integer', 'min:0'],
        ]);

        $validator->after(function ($validator) use ($prepared): void {
            if ((int) ($prepared['max_seats'] ?? 0) < (int) ($prepared['enrolled_count'] ?? 0)) {
                $validator->errors()->add('max_seats', 'Section capacity cannot be lower than the current enrolled count.');
            }

            if (Section::modalityRequiresRoom($prepared['modality'] ?? null) && blank($prepared['room'] ?? null)) {
                $validator->errors()->add('room', 'Room is required for on-site and blended section planning.');
            }

            if (Section::modalityRequiresRoom($prepared['modality'] ?? null) && filled($prepared['room'] ?? null)) {
                $roomIsActive = Room::query()
                    ->where('code', $prepared['room'])
                    ->where('is_active', true)
                    ->exists();

                if (! $roomIsActive) {
                    $validator->errors()->add('room', 'Selected room must exist in the active room catalog.');
                }
            }

            $curriculum = Curriculum::query()->find($prepared['curriculum_id'] ?? null);

            if ($curriculum instanceof Curriculum && (int) $curriculum->program_id !== (int) ($prepared['program_id'] ?? 0)) {
                $validator->errors()->add('curriculum_id', 'Selected curriculum must belong to the selected program.');
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
        $modality = filled($data['modality'] ?? null) ? trim((string) $data['modality']) : null;
        $room = filled($data['room'] ?? null) ? trim((string) $data['room']) : null;

        if (! Section::modalityRequiresRoom($modality)) {
            $room = null;
        }

        return [
            ...$data,
            'term_id' => $this->integerValue($data['term_id'] ?? $section?->term_id),
            'program_id' => $this->integerValue($data['program_id'] ?? $section?->program_id),
            'curriculum_id' => $this->integerValue($data['curriculum_id'] ?? $section?->curriculum_id),
            'year_level' => filled($data['year_level'] ?? null) ? trim((string) $data['year_level']) : null,
            'curriculum_period' => filled($data['curriculum_period'] ?? null) ? trim((string) $data['curriculum_period']) : null,
            'name' => filled($data['name'] ?? null) ? trim((string) $data['name']) : null,
            'modality' => $modality,
            'room' => $room,
            'max_seats' => $this->integerValue($data['max_seats'] ?? $section?->max_seats),
            'enrolled_count' => $this->integerValue($data['enrolled_count'] ?? $section?->enrolled_count ?? 0),
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
