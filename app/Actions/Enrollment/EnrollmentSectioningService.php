<?php

namespace App\Actions\Enrollment;

use App\Models\Enrollment;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentSectioningService
{
    public function assign(Enrollment $enrollment, Section $section, SectionDeliveryGroup $group, User $registrar): Enrollment
    {
        $this->authorize($registrar);

        return DB::transaction(function () use ($enrollment, $section, $group): Enrollment {
            $lockedEnrollment = Enrollment::query()
                ->with('studentProfile')
                ->lockForUpdate()
                ->findOrFail($enrollment->id);
            $lockedSection = Section::query()
                ->lockForUpdate()
                ->findOrFail($section->id);
            $lockedGroup = SectionDeliveryGroup::query()
                ->lockForUpdate()
                ->findOrFail($group->id);

            $this->assertAssignable($lockedEnrollment, $lockedSection, $lockedGroup);

            $willConsumeSectionSeat = (int) $lockedEnrollment->section_id !== (int) $lockedSection->id;
            $willConsumeGroupSeat = (int) $lockedEnrollment->section_delivery_group_id !== (int) $lockedGroup->id;

            if ($willConsumeSectionSeat && (int) $lockedSection->enrolled_count >= (int) $lockedSection->max_seats) {
                throw ValidationException::withMessages([
                    'section_id' => 'Selected section is already at capacity.',
                ]);
            }

            if ($willConsumeGroupSeat && (int) $lockedGroup->assigned_count >= (int) $lockedGroup->capacity) {
                throw ValidationException::withMessages([
                    'section_delivery_group_id' => 'Selected delivery group is already at capacity.',
                ]);
            }

            $this->decrementPreviousAssignment($lockedEnrollment, $lockedSection, $lockedGroup);

            if ($willConsumeSectionSeat) {
                $lockedSection->increment('enrolled_count');
            }

            if ($willConsumeGroupSeat) {
                $lockedGroup->increment('assigned_count');
            }

            $lockedEnrollment->forceFill([
                'section_id' => $lockedSection->id,
                'section_delivery_group_id' => $lockedGroup->id,
                'modality' => $lockedGroup->modality,
            ])->save();

            return $lockedEnrollment->refresh();
        });
    }

    /**
     * @return Collection<int, SectionDeliveryGroup>
     */
    public function rankedCompatibleGroups(Enrollment $enrollment): Collection
    {
        $enrollment->loadMissing('studentProfile');
        $studentProfile = $enrollment->studentProfile;

        return SectionDeliveryGroup::query()
            ->with(['section', 'deliveryPattern'])
            ->where('status', SectionDeliveryGroup::StatusActive)
            ->whereColumn('assigned_count', '<', 'capacity')
            ->whereHas('section', function ($query) use ($enrollment, $studentProfile): void {
                $query->where('term_id', $enrollment->term_id);

                if ($studentProfile?->program_id !== null) {
                    $query->where('program_id', $studentProfile->program_id);
                }

                if (filled($enrollment->year_level)) {
                    $query->where('year_level', $enrollment->year_level);
                }
            })
            ->get()
            ->sortByDesc(fn (SectionDeliveryGroup $group): int => $this->compatibilityScore($enrollment, $group))
            ->values();
    }

    private function authorize(User $registrar): void
    {
        if ($registrar->can('manage-sections') || $registrar->can('manage-schedules')) {
            return;
        }

        throw new AuthorizationException('You are not allowed to assign students to section delivery groups.');
    }

    private function assertAssignable(Enrollment $enrollment, Section $section, SectionDeliveryGroup $group): void
    {
        $enrollment->loadMissing('studentProfile');

        if ((int) $group->section_id !== (int) $section->id) {
            throw ValidationException::withMessages([
                'section_delivery_group_id' => 'Selected delivery group does not belong to the selected section.',
            ]);
        }

        if ((int) $section->term_id !== (int) $enrollment->term_id) {
            throw ValidationException::withMessages([
                'section_id' => 'Selected section does not belong to the enrollment term.',
            ]);
        }

        if ($group->status !== SectionDeliveryGroup::StatusActive) {
            throw ValidationException::withMessages([
                'section_delivery_group_id' => 'Selected delivery group is not active for assignment.',
            ]);
        }

        if ($enrollment->studentProfile?->program_id !== null && (int) $section->program_id !== (int) $enrollment->studentProfile->program_id) {
            throw ValidationException::withMessages([
                'section_id' => 'Selected section does not match the student program.',
            ]);
        }

        if (filled($enrollment->year_level) && filled($section->year_level) && $enrollment->year_level !== $section->year_level) {
            throw ValidationException::withMessages([
                'section_id' => 'Selected section does not match the enrollment year or grade level.',
            ]);
        }

        if ($section->curriculum_id === null || blank($section->year_level) || blank($section->curriculum_period)) {
            throw ValidationException::withMessages([
                'section_id' => 'Selected section is missing curriculum scope and cannot prove subject-set compatibility.',
            ]);
        }
    }

    private function decrementPreviousAssignment(Enrollment $enrollment, Section $newSection, SectionDeliveryGroup $newGroup): void
    {
        if ($enrollment->section_id !== null && (int) $enrollment->section_id !== (int) $newSection->id) {
            Section::query()
                ->whereKey($enrollment->section_id)
                ->where('enrolled_count', '>', 0)
                ->decrement('enrolled_count');
        }

        if ($enrollment->section_delivery_group_id !== null && (int) $enrollment->section_delivery_group_id !== (int) $newGroup->id) {
            SectionDeliveryGroup::query()
                ->whereKey($enrollment->section_delivery_group_id)
                ->where('assigned_count', '>', 0)
                ->decrement('assigned_count');
        }
    }

    private function compatibilityScore(Enrollment $enrollment, SectionDeliveryGroup $group): int
    {
        $score = $group->availableSeats();

        if ($enrollment->modality !== null && $enrollment->modality === $group->modality) {
            $score += 1000;
        }

        return $score;
    }
}
