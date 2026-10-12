<?php

namespace App\Actions\Scheduling;

use App\Models\PublishedTimetableMeeting;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

        if ($section instanceof Section) {
            $current = $section->fresh();
            if ($current->confirmed_at !== null || PublishedTimetableMeeting::query()->where('section_id', $current->id)->exists()) {
                foreach ($prepared as $field => $value) {
                    $currentValue = in_array($field, ['term_offering_id', 'capacity'], true) ? (int) $current->getAttribute($field) : $current->getAttribute($field);
                    if ($value !== $currentValue) {
                        throw ValidationException::withMessages([$field => 'Confirmed or published Class Offering source facts require a controlled change; edit the scheduling inputs separately before publication.']);
                    }
                }
            }
        }

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

    /** @param array<string, mixed> $data */
    public function save(Section $section, array $data, User $actor): Section
    {
        return DB::transaction(function () use ($section, $data, $actor): Section {
            $termId = $section->calendarPackage()->value('term_id') ?? $section->termOffering()->value('term_id');
            if (! $termId || ! Term::query()->whereKey($termId)->lockForUpdate()->first()) {
                throw ValidationException::withMessages(['term_offering_id' => 'The source section requires an existing exact-Term calendar or offering before it can be changed.']);
            }
            $section = Section::query()->lockForUpdate()->findOrFail($section->id);
            Gate::forUser($actor)->authorize('update', $section);
            $section->forceFill($this->prepareForSave($data, $section))->save();

            return $section->refresh();
        }, attempts: 5);
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
