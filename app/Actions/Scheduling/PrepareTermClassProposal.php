<?php

namespace App\Actions\Scheduling;

use App\Actions\Enrollment\RegistrationShortageProjection;
use App\Models\AdmissionApplication;
use App\Models\CourseComponent;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\ProgramAuthority;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermCalendarPackage;
use App\Models\TermCohort;
use App\Models\TermOffering;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PrepareTermClassProposal
{
    /** @return array<string, mixed> */
    public function preview(Term $term): array
    {
        $entries = CurriculumEntry::query()
            ->whereHas('curriculumVersion', fn ($query) => $query->where('state', CurriculumVersion::StateActive))
            ->with(['curriculumVersion.program', 'courseSpecification.course', 'courseSpecification.components'])
            ->orderBy('curriculum_version_id')->orderBy('year_level')->orderBy('sequence')->get();
        $classes = Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
            ->with(['termOffering', 'cohorts', 'deliveryGroups'])->orderBy('id')->get();
        $cohorts = TermCohort::query()->where('term_id', $term->id)->orderBy('id')->get();
        $package = TermCalendarPackage::query()->where('term_id', $term->id)->where('state', TermCalendarPackage::StateActive)->first();
        $catalog = $entries->map(fn (CurriculumEntry $entry): array => [
            'id' => $entry->id,
            'course_title' => $entry->courseSpecification->title,
            'course_code' => $entry->courseSpecification->course->code,
            'program' => $entry->curriculumVersion->program->name,
            'year_level' => $entry->year_level,
            'curriculum_version' => $entry->curriculumVersion->version_code,
            'label' => $entry->curriculumVersion->program->name.' · '.$entry->year_level.' · '.$entry->courseSpecification->title.' ('.$entry->courseSpecification->course->code.') · '.$entry->curriculumVersion->version_code.($entry->term_type !== $term->type ? ' · Additional only in this Term' : ''),
            'curriculum_id' => $entry->curriculum_version_id,
            'course_id' => $entry->courseSpecification->course_id,
            'term_type' => $entry->term_type,
            'modes' => $entry->courseSpecification->allowed_modalities ?? [],
            'meetings' => $entry->courseSpecification->scheduling_treatment === CourseSpecification::SchedulingExternallyArranged
                ? 'Externally arranged — no recurring meetings'
                : $entry->courseSpecification->components->map(fn (CourseComponent $component): ?string => CourseComponent::meetingPatternOptions()[$component->meeting_pattern] ?? $component->meeting_pattern)->filter()->implode(' + '),
            'existing' => $classes->filter(fn (Section $class): bool => (int) $class->termOffering->curriculum_entry_id === $entry->id && $class->state !== Section::StateCancelled)->count(),
        ])->all();
        $rows = $classes->whereNotIn('state', [Section::StateClosed, Section::StateCancelled])->map(fn (Section $class): array => [
            'section_id' => $class->id, 'entry_id' => $class->termOffering->curriculum_entry_id, 'is_catalog' => true,
            'is_confirmed' => $class->confirmed_at !== null,
            'action' => $class->confirmed_at ? 'keep' : 'confirm', 'code' => $class->code,
            'capacity' => $class->capacity, 'source' => $class->source,
            'modality' => $class->delivery_mode ?: $class->termOffering->modality,
            'authority_reference' => $class->authority_reference,
            'cohorts' => $class->cohorts->map(fn (TermCohort $cohort): array => ['id' => $cohort->id, 'count' => $cohort->pivot->expected_count])->all(),
        ])->values()->all();
        foreach ($entries as $entry) {
            if ($entry->term_type !== $term->type || $classes->contains(fn (Section $class): bool => (int) $class->termOffering->curriculum_entry_id === $entry->id && $class->state !== Section::StateCancelled)) {
                continue;
            }
            $modes = $entry->courseSpecification->allowed_modalities ?? [];
            $rows[] = ['section_id' => null, 'entry_id' => $entry->id, 'is_catalog' => true, 'is_confirmed' => false,
                'action' => 'skip', 'code' => '', 'capacity' => null, 'source' => null,
                'modality' => count($modes) === 1 ? $modes[0] : null, 'authority_reference' => null, 'cohorts' => []];
        }

        $demandEvidence = $this->demandEvidence($term);

        return ['catalog' => $catalog, 'classes' => $rows, 'cohorts' => $cohorts, 'demand_evidence' => $demandEvidence,
            'token' => hash('sha256', json_encode([
                $term->fresh()->only(['id', 'type', 'state', 'academic_year_id']), $package?->concurrencyToken(),
                $entries->toArray(), $classes->toArray(), $cohorts->toArray(),
                $demandEvidence['ready_applicants'], $demandEvidence['unmet'],
                ProgramAuthority::query()->whereIn('program_id', $entries->pluck('curriculumVersion.program_id'))->orderBy('id')->get()->toArray(),
            ], JSON_THROW_ON_ERROR))];
    }

    /** @return array{ready_applicants:int,continuing_profiles:int,unmet:list<array<string,mixed>>} */
    public function demandEvidence(Term $term): array
    {
        $ready = AdmissionApplication::query()->where('term_id', $term->id)->where('application_state', AdmissionApplication::StateAdmitted)->get()
            ->filter(fn (AdmissionApplication $application): bool => app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready'])->count();
        $unmet = Enrollment::query()->where('term_id', $term->id)->where('canonical_outcome', Enrollment::OutcomeInProgress)
            ->whereNotNull('current_proposal_version_id')->get()->flatMap(fn (Enrollment $case): array => app(RegistrationShortageProjection::class)->for($case))->all();

        return ['ready_applicants' => $ready, 'continuing_profiles' => StudentProfile::query()->where('lifecycle_status', StudentProfile::LifecycleActive)
            ->whereNull('archived_at')->whereNull('merged_into_id')->where('entry_term_id', '!=', $term->id)->count(), 'unmet' => $unmet];
    }

    /** @param list<array<string,mixed>> $rows
     * @return array{confirmed:int,cancelled:int}
     */
    public function confirm(Term $term, User $actor, array $rows, string $expectedToken): array
    {
        Gate::forUser($actor)->authorize('create', TermOffering::class);

        return DB::transaction(function () use ($term, $actor, $rows, $expectedToken): array {
            $term = Term::query()->lockForUpdate()->findOrFail($term->id);
            $preview = $this->preview($term);
            if ($term->state === Term::StateClosed || ! hash_equals($preview['token'], $expectedToken)) {
                throw ValidationException::withMessages(['proposal' => 'The Term, calendar, curriculum or class demand changed. Reload the proposal and review it again. Nothing was saved.']);
            }
            $existing = collect($preview['classes'])->keyBy('section_id');
            $cohorts = $preview['cohorts']->keyBy('id');
            $seen = [];
            $items = [];
            $cancelled = 0;
            foreach ($rows as $index => $row) {
                $action = $row['action'] ?? 'skip';
                if (! in_array($action, ['skip', 'keep', 'confirm', 'cancel'], true)) {
                    throw ValidationException::withMessages(["classes.{$index}.action" => 'Choose a valid class action.']);
                }
                $id = (int) ($row['section_id'] ?? 0);
                if ($id !== 0 && (! $existing->has($id) || isset($seen[$id]))) {
                    throw ValidationException::withMessages(['classes' => 'Each existing class must belong to this exact Term and appear once.']);
                }
                if ($id !== 0) {
                    $seen[$id] = true;
                }
                if ($action === 'skip' || $action === 'keep') {
                    continue;
                }
                if ($action === 'cancel') {
                    Validator::make($row, ['cancellation_reason' => ['required', 'string', 'max:2000']])->validate();
                    $class = Section::query()->lockForUpdate()->findOrFail($id);
                    Gate::forUser($actor)->authorize('update', $class);
                    if (PublishedTimetableMeeting::query()->where('section_id', $id)->exists()
                        || PublishedTimetableVersion::query()->where('term_id', $term->id)->where('state', PublishedTimetableVersion::StatePublished)->exists()
                        || CourseEnrollment::query()->where('section_id', $id)->where('is_current', true)->exists()
                        || EnrollmentSeatReservation::query()->where('section_id', $id)->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())->exists()) {
                        throw ValidationException::withMessages(['classes' => 'Cancellation requires a reason. A published class must use the controlled timetable revision and enrollment-impact path.']);
                    }
                    $class->update(['state' => Section::StateCancelled]);
                    activity()->causedBy($actor)->performedOn($class)->withProperties(['term_id' => $term->id, 'reason' => $row['cancellation_reason']])->log('class_cancelled');
                    $cancelled++;

                    continue;
                }
                $data = Validator::make($row, [
                    'entry_id' => ['required', 'integer', Rule::in(collect($preview['catalog'])->pluck('id')->all())],
                    'code' => ['required', 'string', 'max:64'], 'capacity' => ['required', 'integer', 'min:1'],
                    'source' => ['required', Rule::in(array_keys(Section::confirmableSourceOptions()))],
                    'modality' => ['required', Rule::in(array_keys(TermOffering::modalityOptions()))],
                    'authority_reference' => ['nullable', 'string', 'max:255', 'required_if:source,'.Section::SourceAdditional],
                    'cohorts' => ['required', 'array', 'min:1'], 'cohorts.*.id' => ['required', 'integer', 'distinct'],
                    'cohorts.*.count' => ['required', 'integer', 'min:1'],
                ])->validate();
                $entry = CurriculumEntry::query()->with(['curriculumVersion', 'courseSpecification.components'])->findOrFail($data['entry_id']);
                $revision = $entry->courseSpecification;
                if ($entry->term_type !== $term->type && $data['source'] !== Section::SourceAdditional) {
                    throw ValidationException::withMessages(['classes' => 'A course outside this Term’s curriculum period requires an approved Additional class.']);
                }
                if ($revision->state !== CourseSpecification::StateActive || ! in_array($data['modality'], $revision->allowed_modalities ?? [], true)) {
                    throw ValidationException::withMessages(['classes' => 'Choose an Active Course Revision and one of its approved delivery modes.']);
                }
                if ($revision->scheduling_treatment !== CourseSpecification::SchedulingExternallyArranged
                    && ($revision->components->isEmpty() || $revision->components->contains(function (CourseComponent $component): bool {
                        $pattern = CourseComponent::parseMeetingPattern($component->meeting_pattern);

                        return $pattern === null || (int) round((float) $component->weekly_contact_hours * 60) !== $pattern['count'] * $pattern['duration_minutes'];
                    }))) {
                    throw ValidationException::withMessages(['classes' => 'The approved recurring meeting definition is incomplete. Correct the Course Revision before confirmation.']);
                }
                $counts = collect($data['cohorts'])->mapWithKeys(fn (array $cohort): array => [(int) $cohort['id'] => (int) $cohort['count']])->all();
                foreach ($counts as $cohortId => $count) {
                    if (! $cohorts->has($cohortId) || $cohorts[$cohortId]->confirmed_at === null || $count > $cohorts[$cohortId]->confirmed_count) {
                        throw ValidationException::withMessages(['classes' => 'Link confirmed student sections from this Term and use their attributable learner counts.']);
                    }
                }
                $class = $id ? Section::query()->lockForUpdate()->findOrFail($id) : null;
                if ($class && ($class->confirmed_at || (int) $class->termOffering->curriculum_entry_id !== $entry->id || $class->capacity !== (int) $data['capacity'])) {
                    throw ValidationException::withMessages(['classes' => 'Retain confirmed classes unchanged. Correct a Draft capacity at its source, then reload the proposal.']);
                }
                if (! $class) {
                    if (Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
                        ->where(fn ($query) => $query->where('code', trim($data['code']))->orWhere('class_reference', trim($data['code'])))->exists()) {
                        throw ValidationException::withMessages(['classes' => 'Every class needs its own reference in this Term.']);
                    }
                    $offering = TermOffering::query()->firstOrCreate(['term_id' => $term->id, 'curriculum_entry_id' => $entry->id, 'delivery_variant' => TermOffering::ArrangementNormalClass], [
                        'category' => TermOffering::CategoryRegular, 'modality' => $data['modality'], 'expected_count' => array_sum($counts), 'state' => TermOffering::StatePendingScheduling,
                    ]);
                    if ($offering->state !== TermOffering::StatePendingScheduling) {
                        throw ValidationException::withMessages(['classes' => 'This course source is already published or cancelled. Use its controlled revision path to change the class set.']);
                    }
                    $class = Section::query()->create(['term_offering_id' => $offering->id, 'code' => trim($data['code']), 'capacity' => $data['capacity'], 'state' => Section::StatePlanned]);
                    $class->deliveryGroups()->create(['name' => trim($data['code']), 'expected_count' => array_sum($counts), 'modality' => $data['modality'], 'state' => SectionDeliveryGroup::StatePlanned]);
                }
                $class = app(PrepareClassOffering::class)->execute($term, $class, $actor, [
                    'class_reference' => trim($data['code']), 'source' => $data['source'], 'delivery_mode' => $data['modality'], 'authority_reference' => $data['authority_reference'] ?? null,
                ]);
                $items[] = ['section' => $class, 'cohort_counts' => $counts, 'authority_reference' => $data['authority_reference'] ?? null];
            }
            app(ConfirmClassOffering::class)->batch($actor, $items, $term->id);
            $countsByCourse = [];
            foreach (Section::query()->whereHas('termOffering', fn ($query) => $query->where('term_id', $term->id))
                ->whereNotIn('state', [Section::StateCancelled, Section::StateClosed])->whereNotNull('confirmed_at')->with(['courseRevision', 'cohorts'])->get() as $class) {
                foreach ($class->cohorts as $cohort) {
                    $key = $class->courseRevision->course_id.':'.$cohort->id;
                    $countsByCourse[$key] = ($countsByCourse[$key] ?? 0) + (int) $cohort->pivot->expected_count;
                    if ($countsByCourse[$key] > (int) $cohort->confirmed_count) {
                        throw ValidationException::withMessages(['classes' => 'Shared and split classes cannot count the same student-section learners twice for one course. Reconcile the counts before confirmation.']);
                    }
                }
            }

            return ['confirmed' => count($items), 'cancelled' => $cancelled];
        }, attempts: 5);
    }
}
