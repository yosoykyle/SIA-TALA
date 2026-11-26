<?php

namespace App\Http\Controllers;

use App\Actions\Scheduling\BuildTimetableVersionOutput;
use App\Filament\Pages\TermTimetable;
use App\Models\PublishedTimetableVersion;
use App\Models\User;
use App\Support\TimetablePresentation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TimetableVersionPrintController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        PublishedTimetableVersion $version,
        BuildTimetableVersionOutput $output,
    ): View {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'timetableView' => ['sometimes', 'in:whole,section,faculty,room,list'],
            'timetableFormat' => ['sometimes', 'in:week,list'],
            'viewFilter' => ['nullable', 'string', 'max:255'],
            'programFilter' => ['nullable', 'string', 'max:255'],
            'yearFilter' => ['nullable', 'string', 'max:255'],
            'tableSearch' => ['nullable', 'string', 'max:255'],
            'applyFilters' => ['sometimes', 'boolean'],
        ]);

        $returnCriteria = array_intersect_key($validated, array_flip([
            'timetableView',
            'timetableFormat',
            'viewFilter',
            'programFilter',
            'yearFilter',
            'tableSearch',
        ]));

        $schedule = $output->execute($version, $actor, $request);

        if ($request->boolean('applyFilters')) {
            $schedule = $this->applyPrintFilters($schedule, $version, $returnCriteria);
        }

        return view('schedules.print', [
            'schedule' => [
                ...$schedule,
                'back_url' => TermTimetable::getUrl(['termId' => $version->term_id, 'selectedVersionId' => $version->id, ...$returnCriteria]),
                'back_label' => 'Return to official timetable',
            ],
        ]);
    }

    /**
     * Filter the printed official timetable rows when explicitly requested from Step 4 print options.
     *
     * @param  array<string, mixed>  $schedule
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function applyPrintFilters(array $schedule, PublishedTimetableVersion $version, array $criteria): array
    {
        $rawView = (string) ($criteria['timetableView'] ?? 'whole');
        $timetableView = $rawView === 'list' ? 'whole' : $rawView;
        $viewFilter = isset($criteria['viewFilter']) && trim((string) $criteria['viewFilter']) !== ''
            ? trim((string) $criteria['viewFilter'])
            : null;
        $programFilter = isset($criteria['programFilter']) && trim((string) $criteria['programFilter']) !== ''
            ? trim((string) $criteria['programFilter'])
            : null;
        $yearFilter = isset($criteria['yearFilter']) && trim((string) $criteria['yearFilter']) !== ''
            ? trim((string) $criteria['yearFilter'])
            : null;
        $tableSearch = isset($criteria['tableSearch']) && trim((string) $criteria['tableSearch']) !== ''
            ? trim((string) $criteria['tableSearch'])
            : null;

        $hasActiveFilter = ($timetableView !== 'whole' && $viewFilter !== null)
            || $programFilter !== null
            || $yearFilter !== null
            || $tableSearch !== null;

        if (! $hasActiveFilter) {
            return $schedule;
        }

        $version->loadMissing('scheduleRun');
        $meetings = $version->meetings()
            ->with([
                'faculty',
                'room',
                'classOffering.courseRevision.course',
                'classOffering.cohorts.program',
                'classOffering.cohorts.curriculumVersion.entries.courseSpecification',
                'schedulingDemand.courseComponent.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section.cohorts.program',
                'schedulingDemand.sectionDeliveryGroup.section.cohorts.curriculumVersion.entries.courseSpecification',
                'schedulingDemand.termOffering.curriculumEntry.curriculumVersion.program',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
            ])
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $presentation = TimetablePresentation::forPublished($version, $meetings);
        $enrichedMeetings = TermTimetable::enrichMeetingsWithScopeMetadata(
            $presentation['meetings'] ?? [],
            (int) $version->term_id,
        );

        $matchingIds = [];
        $sectionLabelLookup = [];
        foreach ($enrichedMeetings as $meeting) {
            foreach ($meeting['sections'] ?? [] as $section) {
                $sectionLabelLookup[(string) ($section['id'] ?? '')] = (string) ($section['label'] ?? '');
            }
            if (TermTimetable::meetingMatchesCriteria(
                $meeting,
                $timetableView,
                $viewFilter,
                $programFilter,
                $yearFilter,
                $tableSearch,
            )) {
                $matchingIds[(string) ($meeting['id'] ?? '')] = true;
            }
        }

        $rows = is_array($schedule['rows'] ?? null) ? array_values($schedule['rows']) : [];
        $filteredRows = [];
        foreach ($meetings->values() as $index => $meetingModel) {
            if (isset($matchingIds[(string) $meetingModel->getKey()]) && isset($rows[$index])) {
                $filteredRows[] = $rows[$index];
            }
        }

        $filterParts = [];
        if ($timetableView === 'section' && $viewFilter !== null) {
            $resolvedSectionLabel = $sectionLabelLookup[$viewFilter] ?? TermTimetable::formatSectionDisplayLabel($viewFilter);
            $filterParts[] = 'Section: '.$resolvedSectionLabel;
        } elseif ($timetableView === 'faculty' && $viewFilter !== null) {
            $filterParts[] = 'Teacher: '.$viewFilter;
        } elseif ($timetableView === 'room' && $viewFilter !== null) {
            $filterParts[] = 'Room: '.$viewFilter;
        }

        if ($programFilter !== null) {
            $filterParts[] = 'Program: '.$programFilter;
        }

        if ($yearFilter !== null) {
            $filterParts[] = 'Year level: '.$yearFilter;
        }

        if ($tableSearch !== null) {
            $filterParts[] = 'Search: '.$tableSearch;
        }

        $filterSummary = implode(' · ', $filterParts);
        $schedule['rows'] = $filteredRows;
        $schedule['filter_description'] = $filterSummary;
        $schedule['role_filter_context'] = $filterSummary;

        return $schedule;
    }
}
