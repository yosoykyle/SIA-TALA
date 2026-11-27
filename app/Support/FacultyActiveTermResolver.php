<?php

namespace App\Support;

use App\Models\ClassOfferingTeachingAssignment;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;

final class FacultyActiveTermResolver
{
    public function resolve(?User $faculty = null): ?Term
    {
        $activeCalendarTerm = Term::query()
            ->with('academicYear')
            ->where('state', '!=', Term::StateClosed)
            ->whereHas('calendarPackages', function ($query): void {
                $query->where('state', TermCalendarPackage::StateActive);
            })
            ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [Term::StateActive])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        if ($activeCalendarTerm instanceof Term) {
            return $activeCalendarTerm;
        }

        $activeStateTerm = Term::query()
            ->with('academicYear')
            ->where('state', Term::StateActive)
            ->latest('starts_on')
            ->latest('id')
            ->first();

        if ($activeStateTerm instanceof Term) {
            return $activeStateTerm;
        }

        if ($faculty instanceof User) {
            $meetingTermIds = SectionMeeting::query()
                ->activeOfficial()
                ->where('faculty_user_id', $faculty->id)
                ->whereHas('schedulingDemand.termOffering', function ($query): void {
                    $query->where('state', '!=', TermOffering::StateCancelled);
                })
                ->with('schedulingDemand.termOffering:id,term_id')
                ->get()
                ->pluck('schedulingDemand.termOffering.term_id');

            $assignmentTermIds = ClassOfferingTeachingAssignment::query()
                ->where('faculty_user_id', $faculty->id)
                ->where('state', ClassOfferingTeachingAssignment::StateActive)
                ->whereHas('termOffering', function ($query): void {
                    $query->where('state', '!=', TermOffering::StateCancelled);
                })
                ->with('termOffering:id,term_id')
                ->get()
                ->pluck('termOffering.term_id');

            $facultyTermIds = $meetingTermIds
                ->merge($assignmentTermIds)
                ->filter()
                ->unique()
                ->values();

            if ($facultyTermIds->isNotEmpty()) {
                $facultyTerm = Term::query()
                    ->with('academicYear')
                    ->whereIn('id', $facultyTermIds)
                    ->where('state', '!=', Term::StateClosed)
                    ->orderByRaw('CASE WHEN state = ? THEN 0 ELSE 1 END', [Term::StateActive])
                    ->orderByDesc('starts_on')
                    ->orderByDesc('id')
                    ->first();

                if ($facultyTerm instanceof Term) {
                    return $facultyTerm;
                }
            }
        }

        return null;
    }

    public function resolveId(?User $faculty = null): ?int
    {
        return $this->resolve($faculty)?->id;
    }

    public function scopeTitle(Term $term): string
    {
        $term->loadMissing('academicYear');

        return collect([
            $term->academicYear?->label,
            $term->label,
        ])->filter()->implode(' · ');
    }

    public function scopeMeta(Term $term, bool $hasActiveCoreCalendar, ?string $extra = null): string
    {
        $parts = [];

        if ($term->starts_on !== null && $term->ends_on !== null) {
            $parts[] = $term->starts_on->year === $term->ends_on->year
                ? $term->starts_on->format('M j').' – '.$term->ends_on->format('M j, Y')
                : $term->starts_on->format('M j, Y').' – '.$term->ends_on->format('M j, Y');
        }

        $parts[] = $hasActiveCoreCalendar
            ? 'Term calendar active'
            : 'Term calendar not active';

        if (filled($extra)) {
            $parts[] = trim((string) $extra);
        }

        return implode(' · ', $parts);
    }
}
