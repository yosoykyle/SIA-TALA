<?php

namespace App\Support;

use App\Models\SectionMeeting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FacultyScheduleFilters
{
    /** @param array<string, int|string> $criteria
     * @param  Collection<int, SectionMeeting>  $ownedMeetings
     */
    public static function describe(array $criteria, Collection $ownedMeetings): string
    {
        $labels = [];
        foreach ($criteria as $field => $value) {
            $labels[] = match ($field) {
                'course_id' => 'Course: '.($ownedMeetings->pluck('schedulingDemand.termOffering.curriculumEntry.courseSpecification.course.code', 'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course.id')->get($value) ?? 'Unavailable selection'),
                'class_id' => 'Class: '.($ownedMeetings->pluck('schedulingDemand.sectionDeliveryGroup.section.code', 'schedulingDemand.sectionDeliveryGroup.section.id')->get($value) ?? 'Unavailable selection'),
                'room_id' => 'Room: '.($ownedMeetings->pluck('room.code', 'room_id')->get($value) ?? 'Unavailable selection'),
                'day_of_week' => 'Day: '.SectionMeeting::dayOptions()[$value],
                'modality' => 'Modality: '.SectionMeeting::modalityOptions()[$value],
                default => 'Search: '.$value,
            };
        }

        return $labels === [] ? 'No filters applied' : implode(' · ', $labels);
    }

    /** @param array<string, mixed> $input
     * @return array<string, int|string>
     */
    public static function normalize(array $input): array
    {
        $criteria = Validator::make($input, [
            'course_id' => ['nullable', 'integer', 'min:1'],
            'class_id' => ['nullable', 'integer', 'min:1'],
            'room_id' => ['nullable', 'integer', 'min:1'],
            'day_of_week' => ['nullable', 'integer', Rule::in(array_keys(SectionMeeting::dayOptions()))],
            'modality' => ['nullable', Rule::in(array_keys(SectionMeeting::modalityOptions()))],
            'search' => ['nullable', 'string', 'max:255'],
        ])->validate();

        return collect($criteria)->map(fn ($value, $key) => $key === 'search' ? trim((string) $value) : $value)
            ->filter(fn ($value): bool => $value !== null && $value !== '')->all();
    }

    /** @param array<string, mixed> $criteria */
    public static function apply(Builder $query, array $criteria): Builder
    {
        $criteria = self::normalize($criteria);
        foreach (['room_id', 'day_of_week', 'modality'] as $field) {
            if (isset($criteria[$field])) {
                $query->where($field, $criteria[$field]);
            }
        }
        if (isset($criteria['class_id'])) {
            $query->whereHas('schedulingDemand.sectionDeliveryGroup.section', fn (Builder $section): Builder => $section->whereKey($criteria['class_id']));
        }
        if (isset($criteria['course_id'])) {
            $query->whereHas('schedulingDemand.termOffering.curriculumEntry.courseSpecification', fn (Builder $specification): Builder => $specification->where('course_id', $criteria['course_id']));
        }
        if (isset($criteria['search'])) {
            self::search($query, $criteria['search']);
        }

        return $query;
    }

    public static function search(Builder $query, string $search): Builder
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        return $query->where(fn (Builder $matching): Builder => $matching
            ->whereHas('schedulingDemand.termOffering.curriculumEntry.courseSpecification', fn (Builder $specification): Builder => $specification
                ->where(fn (Builder $identity): Builder => $identity->where('title', 'like', $pattern)
                    ->orWhereHas('course', fn (Builder $course): Builder => $course->where('code', 'like', $pattern))))
            ->orWhereHas('schedulingDemand.sectionDeliveryGroup.section', fn (Builder $section): Builder => $section->where('code', 'like', $pattern))
            ->orWhereHas('room', fn (Builder $room): Builder => $room->where('code', 'like', $pattern)));
    }
}
