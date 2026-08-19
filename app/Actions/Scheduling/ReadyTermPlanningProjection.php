<?php

namespace App\Actions\Scheduling;

use App\Models\FacultyAvailabilityDeclaration;
use App\Models\Room;
use App\Models\Section;
use App\Models\Term;
use App\Models\TermCalendarPackage;

final class ReadyTermPlanningProjection
{
    /**
     * @return array{ready: bool, term_id: int, blockers: list<array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string}>}
     */
    public function forTerm(Term $term): array
    {
        $blockers = [];
        $activePackage = TermCalendarPackage::query()
            ->where('term_id', $term->id)
            ->where('state', TermCalendarPackage::StateActive)
            ->first();

        if (! $activePackage instanceof TermCalendarPackage) {
            $blockers[] = $this->blocker('calendar_not_active', 'Term Calendar Package', 'Registrar', 'No active package exists for this exact Term.', 'Activate a passing package.', 'Correct the Draft package and retry.');
        }

        $classes = Section::query()
            ->where('term_calendar_package_id', $activePackage?->id)
            ->get();

        if ($classes->isEmpty() || $classes->contains(fn (Section $section): bool => $section->confirmed_at === null)) {
            $blockers[] = $this->blocker('classes_not_confirmed', 'Cohorts and Class Offerings', 'Registrar', 'Every ready Class Offering must be confirmed.', 'Resolve class authority, cohorts, capacity, and meeting requirements.', 'Correct the affected Draft class and confirm it.');
        }

        $facultyIds = $classes->flatMap(fn (Section $section) => $section->deliveryGroups()->with('schedulingDemands')->get())
            ->flatMap(fn ($group) => $group->schedulingDemands->pluck('fixed_faculty_user_id'))
            ->filter()->unique();

        if ($facultyIds->isNotEmpty() && FacultyAvailabilityDeclaration::query()
            ->where('term_id', $term->id)
            ->whereIn('faculty_user_id', $facultyIds)
            ->distinct('faculty_user_id')
            ->count('faculty_user_id') !== $facultyIds->count()) {
            $blockers[] = $this->blocker('faculty_availability_incomplete', 'Teaching resources', 'Faculty', 'A required Faculty availability declaration is missing.', 'Faculty records own availability; Registrar reviews readiness.', 'Record or correct the declaration, then regenerate.');
        }

        if (! Room::query()->where('is_active', true)->exists()) {
            $blockers[] = $this->blocker('rooms_unavailable', 'Teaching resources', 'Registrar', 'No active room is available for on-campus planning.', 'Record current room capacity, type, features, and unavailability.', 'Correct the room authority and retry readiness.');
        }

        return ['ready' => $blockers === [], 'term_id' => (int) $term->id, 'blockers' => $blockers];
    }

    /** @return array{code: string, source: string, owner: string, reason: string, next_action: string, recovery: string} */
    private function blocker(string $code, string $source, string $owner, string $reason, string $nextAction, string $recovery): array
    {
        return compact('code', 'source', 'owner', 'reason') + ['next_action' => $nextAction, 'recovery' => $recovery];
    }
}
