<?php

namespace App\Actions\Scheduling;

use App\Models\CurriculumSubject;
use App\Models\Section;
use App\Models\Term;

class TermSchedulingReadinessService
{
    private const MaxSectionSeats = 30;

    /**
     * @return array{
     *     is_ready: bool,
     *     missing_term_fields: list<string>,
     *     section_issues: list<array{section_id:int, section_name:string, missing_fields:list<string>, has_curriculum_demand:bool}>,
     *     room_catalog_mode: string
     * }
     */
    public function evaluateTerm(Term $term): array
    {
        $missingTermFields = $this->missingTermFields($term);
        $sectionIssues = $this->sectionIssues($term);

        return [
            'is_ready' => $missingTermFields === [] && $sectionIssues === [],
            'missing_term_fields' => $missingTermFields,
            'section_issues' => $sectionIssues,
            'room_catalog_mode' => 'sections.room fixed-room rescue catalog',
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

        foreach (['curriculum_id', 'year_level', 'curriculum_period', 'max_seats', 'modality'] as $field) {
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

        if (in_array($section->modality, ['on_site', 'blended'], true) && blank($section->room)) {
            $missingFields[] = 'room';
        }

        if ($section->curriculum !== null && (int) $section->curriculum->program_id !== (int) $section->program_id) {
            $missingFields[] = 'curriculum_program_mismatch';
        }

        $hasCurriculumDemand = $this->hasCurriculumDemand($section);

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
}
