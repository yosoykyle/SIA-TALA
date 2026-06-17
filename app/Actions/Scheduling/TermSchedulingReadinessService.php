<?php

namespace App\Actions\Scheduling;

use App\Actions\AcademicFoundation\CurriculumScopeReadinessService;
use App\Models\CurriculumReadinessScope;
use App\Models\CurriculumSubject;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\FacultySubjectEligibility;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\Term;
use Illuminate\Support\Collection;

class TermSchedulingReadinessService
{
    private const MaxSectionSeats = 30;

    public function __construct(private readonly CurriculumScopeReadinessService $curriculumReadinessService) {}

    /**
     * @return array{
     *     is_ready: bool,
     *     missing_term_fields: list<string>,
     *     section_issues: list<array{section_id:int, section_name:string, missing_fields:list<string>, has_curriculum_demand:bool}>,
     *     delivery_group_issues: list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, missing_fields:list<string>}>,
     *     faculty_input_issues: list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, subject_id:int, subject_code:?string, missing_inputs:list<string>, eligible_faculty_count:int, schedulable_faculty_count:int}>,
     *     room_catalog_mode: string
     * }
     */
    public function evaluateTerm(Term $term): array
    {
        $missingTermFields = $this->missingTermFields($term);
        $sectionIssues = $this->sectionIssues($term);
        $deliveryGroupIssues = $sectionIssues === []
            ? $this->deliveryGroupIssues($term)
            : [];
        $facultyInputIssues = $sectionIssues === [] && $deliveryGroupIssues === []
            ? $this->facultyInputIssues($term)
            : [];

        return [
            'is_ready' => $missingTermFields === [] && $sectionIssues === [] && $deliveryGroupIssues === [] && $facultyInputIssues === [],
            'missing_term_fields' => $missingTermFields,
            'section_issues' => $sectionIssues,
            'delivery_group_issues' => $deliveryGroupIssues,
            'faculty_input_issues' => $facultyInputIssues,
            'room_catalog_mode' => 'section_delivery_groups.room fixed-room catalog; sections.room legacy compatibility',
        ];
    }

    /**
     * @return list<string>
     */
    private function missingTermFields(Term $term): array
    {
        $requiredFields = [
            'term_name',
            'term_start_date',
            'term_end_date',
            'scheduling_starts_at',
        ];

        return collect($requiredFields)
            ->filter(fn (string $field): bool => blank($term->{$field}))
            ->values()
            ->all();
    }

    /**
     * @return list<array{section_id:int, section_name:string, missing_fields:list<string>, has_curriculum_demand:bool}>
     */
    private function sectionIssues(Term $term): array
    {
        $sections = Section::query()
            ->with('curriculum')
            ->where('term_id', $term->id)
            ->orderBy('id')
            ->get();

        if ($sections->isEmpty()) {
            return [[
                'section_id' => 0,
                'section_name' => 'No sections',
                'missing_fields' => ['sections'],
                'has_curriculum_demand' => false,
            ]];
        }

        return $sections
            ->map(fn (Section $section): ?array => $this->issueForSection($section))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{section_id:int, section_name:string, missing_fields:list<string>, has_curriculum_demand:bool}|null
     */
    private function issueForSection(Section $section): ?array
    {
        $missingFields = [];

        foreach (['curriculum_id', 'year_level', 'curriculum_period', 'max_seats'] as $field) {
            if (blank($section->{$field})) {
                $missingFields[] = $field;
            }
        }

        if ($section->max_seats !== null && ((int) $section->max_seats < 1 || (int) $section->max_seats > self::MaxSectionSeats)) {
            $missingFields[] = 'max_seats_capacity_contract';
        }

        if ($section->max_seats !== null && (int) $section->max_seats < (int) $section->enrolled_count) {
            $missingFields[] = 'max_seats_below_enrolled_count';
        }

        if ($section->curriculum !== null && (int) $section->curriculum->program_id !== (int) $section->program_id) {
            $missingFields[] = 'curriculum_program_mismatch';
        }

        $hasCurriculumDemand = $this->hasCurriculumDemand($section);

        if ($hasCurriculumDemand) {
            $missingFields = [
                ...$missingFields,
                ...$this->curriculumReadinessMissingFields($section),
            ];
        }

        if ($missingFields === [] && $hasCurriculumDemand) {
            return null;
        }

        return [
            'section_id' => (int) $section->id,
            'section_name' => (string) $section->name,
            'missing_fields' => $missingFields,
            'has_curriculum_demand' => $hasCurriculumDemand,
        ];
    }

    /**
     * @return list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, missing_fields:list<string>}>
     */
    private function deliveryGroupIssues(Term $term): array
    {
        $sections = Section::query()
            ->with(['deliveryGroups.deliveryPattern'])
            ->where('term_id', $term->id)
            ->orderBy('id')
            ->get();

        return $sections
            ->flatMap(function (Section $section): array {
                if ($section->deliveryGroups->isEmpty()) {
                    return [[
                        'section_id' => (int) $section->id,
                        'section_name' => (string) $section->name,
                        'section_delivery_group_id' => null,
                        'delivery_group_name' => null,
                        'missing_fields' => ['section_delivery_groups'],
                    ]];
                }

                $activeGroups = $section->deliveryGroups
                    ->where('status', SectionDeliveryGroup::StatusActive)
                    ->values();

                $issues = [];

                if ($activeGroups->isEmpty()) {
                    $issues[] = [
                        'section_id' => (int) $section->id,
                        'section_name' => (string) $section->name,
                        'section_delivery_group_id' => null,
                        'delivery_group_name' => null,
                        'missing_fields' => ['active_section_delivery_group'],
                    ];
                }

                foreach ($section->deliveryGroups as $group) {
                    $missingFields = $this->missingDeliveryGroupFields($section, $group);

                    if ($missingFields !== []) {
                        $issues[] = [
                            'section_id' => (int) $section->id,
                            'section_name' => (string) $section->name,
                            'section_delivery_group_id' => (int) $group->id,
                            'delivery_group_name' => (string) $group->name,
                            'missing_fields' => $missingFields,
                        ];
                    }
                }

                return $issues;
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function missingDeliveryGroupFields(Section $section, SectionDeliveryGroup $group): array
    {
        $missingFields = [];

        foreach (['delivery_pattern_id', 'modality', 'capacity', 'status'] as $field) {
            if (blank($group->{$field})) {
                $missingFields[] = $field;
            }
        }

        if ($group->deliveryPattern === null) {
            $missingFields[] = 'delivery_pattern';
        } elseif (! $group->deliveryPattern->is_active) {
            $missingFields[] = 'active_delivery_pattern';
        } elseif ($group->deliveryPattern->modality !== null && $group->deliveryPattern->modality !== $group->modality) {
            $missingFields[] = 'delivery_pattern_modality_mismatch';
        }

        if ($group->capacity !== null && (int) $group->capacity < 1) {
            $missingFields[] = 'delivery_group_capacity';
        }

        if ($group->capacity !== null && (int) $group->capacity > (int) $section->max_seats) {
            $missingFields[] = 'delivery_group_capacity_above_section_capacity';
        }

        if ($group->capacity !== null && (int) $group->capacity < (int) $group->assigned_count) {
            $missingFields[] = 'delivery_group_capacity_below_assigned_count';
        }

        if (SectionDeliveryGroup::modalityRequiresRoom($group->modality) && blank($group->room)) {
            $missingFields[] = 'delivery_group_room';
        }

        return array_values(array_unique($missingFields));
    }

    private function hasCurriculumDemand(Section $section): bool
    {
        if ($section->curriculum_id === null || blank($section->year_level) || blank($section->curriculum_period)) {
            return false;
        }

        return CurriculumSubject::query()
            ->where('curriculum_id', $section->curriculum_id)
            ->where('year_level', $section->year_level)
            ->where('semester', $section->curriculum_period)
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function curriculumReadinessMissingFields(Section $section): array
    {
        if ($section->curriculum_id === null || blank($section->year_level) || blank($section->curriculum_period)) {
            return [];
        }

        $scope = $this->curriculumReadinessService->findScope(
            (int) $section->curriculum_id,
            (string) $section->year_level,
            (string) $section->curriculum_period,
        );

        if (! $scope instanceof CurriculumReadinessScope) {
            return ['curriculum_readiness_scope'];
        }

        $scope = $this->curriculumReadinessService->refreshStatus($scope);

        return match ($scope->status) {
            CurriculumReadinessScope::StatusReadyForScheduling => [],
            CurriculumReadinessScope::StatusBlocked => ['curriculum_scope_blocked'],
            default => ['curriculum_scope_needs_review'],
        };
    }

    /**
     * @return list<array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, subject_id:int, subject_code:?string, missing_inputs:list<string>, eligible_faculty_count:int, schedulable_faculty_count:int}>
     */
    private function facultyInputIssues(Term $term): array
    {
        $demands = $this->curriculumSubjectDemands($term);

        if ($demands->isEmpty()) {
            return [];
        }

        $subjectIds = $demands
            ->pluck('subject_id')
            ->unique()
            ->values()
            ->all();

        $eligibleFacultyBySubject = FacultySubjectEligibility::query()
            ->whereIn('subject_id', $subjectIds)
            ->where('status', FacultySubjectEligibility::StatusActive)
            ->where(function ($query) use ($term): void {
                $query->whereNull('term_id')
                    ->orWhere('term_id', $term->id);
            })
            ->get(['faculty_id', 'subject_id'])
            ->groupBy('subject_id')
            ->map(fn (Collection $eligibilities): Collection => $eligibilities
                ->pluck('faculty_id')
                ->unique()
                ->values());

        $availableFacultyIds = FacultyAvailabilitySubmission::query()
            ->where('term_id', $term->id)
            ->whereIn('status', [
                FacultyAvailabilitySubmission::StatusSubmitted,
                FacultyAvailabilitySubmission::StatusLocked,
            ])
            ->whereHas('windows')
            ->pluck('faculty_id')
            ->unique()
            ->values();

        return $demands
            ->map(function (array $demand) use ($eligibleFacultyBySubject, $availableFacultyIds): ?array {
                /** @var Collection<int, int> $eligibleFacultyIds */
                $eligibleFacultyIds = $eligibleFacultyBySubject->get($demand['subject_id'], collect());
                $schedulableFacultyIds = $eligibleFacultyIds->intersect($availableFacultyIds)->values();

                if ($schedulableFacultyIds->isNotEmpty()) {
                    return null;
                }

                $missingInputs = [];

                if ($eligibleFacultyIds->isEmpty()) {
                    $missingInputs[] = 'active_faculty_subject_eligibility';
                }

                $missingInputs[] = 'submitted_or_locked_faculty_availability';

                return [
                    'section_id' => (int) $demand['section_id'],
                    'section_name' => (string) $demand['section_name'],
                    'section_delivery_group_id' => (int) $demand['section_delivery_group_id'],
                    'delivery_group_name' => (string) $demand['delivery_group_name'],
                    'subject_id' => (int) $demand['subject_id'],
                    'subject_code' => $demand['subject_code'],
                    'missing_inputs' => array_values(array_unique($missingInputs)),
                    'eligible_faculty_count' => $eligibleFacultyIds->count(),
                    'schedulable_faculty_count' => 0,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, array{section_id:int, section_name:string, section_delivery_group_id:int|null, delivery_group_name:string|null, subject_id:int, subject_code:?string}>
     */
    private function curriculumSubjectDemands(Term $term): Collection
    {
        $sections = Section::query()
            ->with(['deliveryGroups' => fn ($query) => $query->where('status', SectionDeliveryGroup::StatusActive)->orderBy('id')])
            ->where('term_id', $term->id)
            ->whereNotNull('curriculum_id')
            ->whereNotNull('year_level')
            ->whereNotNull('curriculum_period')
            ->orderBy('id')
            ->get(['id', 'name', 'curriculum_id', 'year_level', 'curriculum_period']);

        $curriculumIds = $sections
            ->pluck('curriculum_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($curriculumIds === []) {
            return collect();
        }

        $curriculumSubjects = CurriculumSubject::query()
            ->with('subject:id,code')
            ->whereIn('curriculum_id', $curriculumIds)
            ->where(function ($query): void {
                $query->whereNull('delivery_rule_override')
                    ->orWhere('delivery_rule_override', '!=', CurriculumSubject::DeliveryOverrideExcludeFromAutoSchedule);
            })
            ->orderBy('curriculum_id')
            ->orderBy('year_level')
            ->orderBy('semester')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $sections
            ->flatMap(fn (Section $section): array => $curriculumSubjects
                ->filter(fn (CurriculumSubject $curriculumSubject): bool => $this->matchesSectionDemand($curriculumSubject, $section))
                ->flatMap(fn (CurriculumSubject $curriculumSubject): array => $section->deliveryGroups
                    ->map(fn (SectionDeliveryGroup $group): array => [
                        'section_id' => (int) $section->id,
                        'section_name' => (string) $section->name,
                        'section_delivery_group_id' => (int) $group->id,
                        'delivery_group_name' => (string) $group->name,
                        'subject_id' => (int) $curriculumSubject->subject_id,
                        'subject_code' => $curriculumSubject->subject?->code,
                    ])
                    ->values()
                    ->all())
                ->values()
                ->all())
            ->values();
    }

    private function matchesSectionDemand(CurriculumSubject $curriculumSubject, Section $section): bool
    {
        return (int) $curriculumSubject->curriculum_id === (int) $section->curriculum_id
            && $curriculumSubject->year_level === $section->year_level
            && $curriculumSubject->semester === $section->curriculum_period;
    }
}
