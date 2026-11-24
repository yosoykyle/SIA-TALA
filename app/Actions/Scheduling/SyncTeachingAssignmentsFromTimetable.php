<?php

namespace App\Actions\Scheduling;

use App\Actions\Grades\ManageTeachingAssignment;
use App\Actions\Grades\SynchronizeOfficialGradeRoster;
use App\Models\ClassOfferingTeachingAssignment;
use App\Models\GradeRoster;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Records teaching assignments from the Faculty named on a published timetable.
 *
 * The Faculty with the most meetings in a class is Designated; any other Faculty on the
 * class's meetings is Co-Faculty. A class whose Designated assignment was recorded by hand
 * is left as it is, so a Registrar decision is never silently replaced.
 */
class SyncTeachingAssignmentsFromTimetable
{
    public const OutcomeAssigned = 'assigned';

    public const OutcomeUnchanged = 'unchanged';

    public const OutcomeManual = 'manual';

    public const OutcomeNotFaculty = 'not_faculty';

    public function __construct(
        private readonly ManageTeachingAssignment $assignments,
        private readonly SynchronizeOfficialGradeRoster $rosters,
    ) {}

    public static function authorityFor(PublishedTimetableVersion $version): string
    {
        return ClassOfferingTeachingAssignment::TimetableAuthorityPrefix.$version->version.' (#'.$version->id.')';
    }

    /**
     * Describe what a sync would change, without writing anything.
     *
     * @return list<array{section_id: int, class: string, outcome: string, designated: ?int, co_faculty: list<int>, add_co_faculty: list<int>, end_co_faculty: list<int>, designate: bool, add_roster: bool}>
     */
    public function plan(PublishedTimetableVersion $version): array
    {
        $meetings = PublishedTimetableMeeting::query()
            ->where('published_timetable_version_id', $version->id)
            ->whereNotNull('faculty_user_id')
            ->orderBy('id')
            ->get(['id', 'section_id', 'faculty_user_id']);

        if ($meetings->isEmpty()) {
            return [];
        }

        $sections = Section::query()->whereIn('id', $meetings->pluck('section_id')->unique())->get()->keyBy('id');
        $facultyIds = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', User::StaffRoleFaculty))
            ->whereIn('id', $meetings->pluck('faculty_user_id')->unique())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $active = ClassOfferingTeachingAssignment::query()
            ->whereIn('section_id', $sections->keys())
            ->where('state', ClassOfferingTeachingAssignment::StateActive)
            ->get()
            ->groupBy('section_id');

        return $meetings->groupBy('section_id')
            ->map(fn (Collection $sectionMeetings, int $sectionId): array => $this->planSection(
                $sections->get($sectionId),
                $sectionMeetings,
                $facultyIds,
                $active->get($sectionId, collect()),
            ))
            ->filter(fn (array $plan): bool => $plan['class'] !== '')
            ->sortBy('class')
            ->values()
            ->all();
    }

    /**
     * Apply the plan for a published timetable version.
     *
     * @return list<array{section_id: int, class: string, outcome: string, designated: ?int, co_faculty: list<int>, add_co_faculty: list<int>, end_co_faculty: list<int>, designate: bool, add_roster: bool}>
     */
    public function apply(PublishedTimetableVersion $version, User $actor): array
    {
        $authority = self::authorityFor($version);
        $plans = $this->plan($version);

        foreach ($plans as $plan) {
            if (! in_array($plan['outcome'], [self::OutcomeAssigned, self::OutcomeUnchanged], true)) {
                continue;
            }

            $section = Section::query()->findOrFail($plan['section_id']);

            if ($plan['designate']) {
                $this->assignments->designate($section, User::query()->findOrFail($plan['designated']), $actor, $authority);
            }

            foreach ($plan['add_co_faculty'] as $facultyId) {
                $this->assignments->addCoFaculty($section, User::query()->findOrFail($facultyId), $actor, $authority);
            }

            ClassOfferingTeachingAssignment::query()
                ->where('section_id', $section->id)
                ->where('role', ClassOfferingTeachingAssignment::RoleCoFaculty)
                ->where('state', ClassOfferingTeachingAssignment::StateActive)
                ->whereIn('faculty_user_id', $plan['end_co_faculty'])
                ->get()
                ->each(fn (ClassOfferingTeachingAssignment $assignment) => $this->assignments->endCoFaculty($assignment, $actor));

            if ($plan['add_roster']) {
                $this->rosters->execute($section, $actor);
            }
        }

        return $plans;
    }

    /**
     * @param  Collection<int, PublishedTimetableMeeting>  $meetings
     * @param  Collection<int, int>  $facultyIds
     * @param  Collection<int, ClassOfferingTeachingAssignment>  $active
     * @return array{section_id: int, class: string, outcome: string, designated: ?int, co_faculty: list<int>, add_co_faculty: list<int>, end_co_faculty: list<int>, designate: bool, add_roster: bool}
     */
    private function planSection(?Section $section, Collection $meetings, Collection $facultyIds, Collection $active): array
    {
        $ranked = $meetings->groupBy('faculty_user_id')
            ->map(fn (Collection $facultyMeetings, int $facultyId): array => [
                'faculty_id' => $facultyId,
                'meetings' => $facultyMeetings->count(),
                'first' => (int) $facultyMeetings->min('id'),
            ])
            ->sortBy([['meetings', 'desc'], ['first', 'asc']])
            ->pluck('faculty_id')
            ->map(fn ($id): int => (int) $id)
            ->values();
        $designatedId = $ranked->first();
        $coFacultyIds = $ranked->slice(1)->values();
        $plan = [
            'section_id' => (int) $section?->id,
            'class' => $section instanceof Section ? $section->displayReference() : '',
            'outcome' => self::OutcomeUnchanged,
            'designated' => $designatedId,
            'co_faculty' => $coFacultyIds->all(),
            'add_co_faculty' => [],
            'end_co_faculty' => [],
            'designate' => false,
            'add_roster' => false,
        ];

        if (! $section instanceof Section || $section->state === Section::StateCancelled) {
            return [...$plan, 'class' => ''];
        }

        $currentDesignated = $active->firstWhere('role', ClassOfferingTeachingAssignment::RoleDesignated);

        if ($currentDesignated instanceof ClassOfferingTeachingAssignment && ! $currentDesignated->isFromPublishedTimetable()) {
            return [...$plan, 'outcome' => self::OutcomeManual, 'designated' => (int) $currentDesignated->faculty_user_id];
        }

        if ($ranked->diff($facultyIds)->isNotEmpty()) {
            return [...$plan, 'outcome' => self::OutcomeNotFaculty];
        }

        $activeCoFaculty = $active->where('role', ClassOfferingTeachingAssignment::RoleCoFaculty);
        $plan['designate'] = (int) $currentDesignated?->faculty_user_id !== $designatedId;
        $plan['add_co_faculty'] = $coFacultyIds
            ->reject(fn (int $facultyId): bool => $activeCoFaculty->contains('faculty_user_id', $facultyId))
            ->values()
            ->all();
        $plan['end_co_faculty'] = $activeCoFaculty
            ->filter(fn (ClassOfferingTeachingAssignment $assignment): bool => $assignment->isFromPublishedTimetable()
                && ! $coFacultyIds->contains((int) $assignment->faculty_user_id))
            ->pluck('faculty_user_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $plan['add_roster'] = $plan['designate'] || $this->needsRoster($section);

        if ($plan['designate'] || $plan['add_co_faculty'] !== [] || $plan['end_co_faculty'] !== []) {
            $plan['outcome'] = self::OutcomeAssigned;
        }

        return $plan;
    }

    private function needsRoster(Section $section): bool
    {
        return ! GradeRoster::query()->where('section_id', $section->id)->exists();
    }
}
