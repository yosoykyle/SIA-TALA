@php
    $results = $profile['results'];
    $selected = $profile['selectedResult'];
    $curriculum = $profile['curriculum'];
    $fromEnrollmentId = request()->integer('fromEnrollment') ?: null;
    $returnEnrollment = $fromEnrollmentId
        ? \App\Models\Enrollment::query()
            ->whereKey($fromEnrollmentId)
            ->where('student_profile_id', $student->id)
            ->first()
        : null;
    $returnEnrollmentUrl = $returnEnrollment && \App\Filament\Resources\Enrollments\EnrollmentResource::canView($returnEnrollment)
        ? \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $returnEnrollment])
        : null;
    $yearLevelLabel = static fn (?string $yearLevel): string => match (trim((string) $yearLevel)) {
        '', '0' => 'Year not recorded',
        '1', '1st Year', 'First Year' => 'Year 1',
        '2', '2nd Year', 'Second Year' => 'Year 2',
        '3', '3rd Year', 'Third Year' => 'Year 3',
        '4', '4th Year', 'Fourth Year' => 'Year 4',
        default => ctype_digit(trim((string) $yearLevel)) ? 'Year '.trim((string) $yearLevel) : trim((string) $yearLevel),
    };
    $satisfiedCourseIds = $curriculum && count($curriculum['required'])
        ? app(\App\Actions\Enrollment\RegistrationAcademicEligibilityQuery::class)->satisfiedCourseIds($student)
        : collect();
    $curriculumGroups = $curriculum && count($curriculum['required'])
        ? collect($curriculum['required'])
            ->groupBy(fn (array $row): string => $yearLevelLabel($row['curriculum_entry']->year_level))
            ->map(fn ($yearRows) => $yearRows->groupBy(fn (array $row): string => filled($row['curriculum_entry']->term_label) ? (string) $row['curriculum_entry']->term_label : 'Term not recorded'))
        : collect();
@endphp
@if ($returnEnrollmentUrl)
    <div style="margin-block-end: .75rem;">
        <x-filament::link :href="$returnEnrollmentUrl" icon="heroicon-o-arrow-left">
            Return to enrollment case{{ $returnEnrollment?->term?->label ? ' ('.$returnEnrollment->term->label.')' : '' }}
        </x-filament::link>
    </div>
@endif
<section class="tala-profile-ledger" aria-labelledby="profile-academic-heading">
    <header class="tala-profile-ledger__toolbar">
        <div><h2 id="profile-academic-heading">Academic record</h2><p class="tala-profile-muted">Released results for the selected term.</p></div>
        @if (count($profile['termOptions']) > 1)
            <label class="tala-profile-control"><span>Term</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="academicTerm">@if ($this->academicTerm === null)<option value="">{{ $profile['termOptions'][$profile['selectedTerm']?->id] ?? 'No enrolled term' }}</option>@endif@foreach ($profile['termOptions'] as $id => $label)@if ($this->academicTerm !== null || (int) $id !== (int) $profile['selectedTerm']?->id)<option value="{{ $id }}">{{ $label }}</option>@endif@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        @elseif ($profile['selectedTerm'])
            <div class="tala-profile-scope"><span class="tala-profile-eyebrow">Term</span><strong>{{ $profile['termOptions'][$profile['selectedTerm']->id] }}</strong></div>
        @endif
        @if ($profile['releasedCount'] > 1 || trim($this->academicSearch) !== '')
            <label class="tala-profile-control"><span>Find a subject</span><x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input type="search" wire:model.live.debounce.350ms="academicSearch" placeholder="Subject or code" /></x-filament::input.wrapper></label>
        @endif
    </header>
    <div class="tala-profile-ledger__metrics">
        <div><span class="tala-profile-muted">Released results</span><strong class="tala-profile-number">{{ $results->total() }}</strong><span class="tala-profile-muted">{{ $profile['selectedTerm']?->label ?? 'No enrolled term' }}</span></div>
        @if ($profile['termAverage'])<x-academic-average :average="$profile['termAverage']" compact />@endif
        <x-academic-average :average="$profile['cumulative']" compact />
    </div>
    @if ($results->isNotEmpty())
        <div @class(['tala-profile-ledger__body', 'tala-content-region', 'tala-profile-ledger__body--short' => $results->total() <= 3]) wire:loading.attr="aria-busy" wire:target="academicTerm,academicSearch,selectAcademicResult,setAcademicPage">
            <x-content-progress label="Loading subject results" wire:loading.delay.short.block wire:target="academicTerm,academicSearch,selectAcademicResult,setAcademicPage" />
            <div class="tala-profile-collection">
                <div class="tala-data-table-scroll tala-profile-table-wrap" role="region" aria-label="Released subject results" tabindex="0">
                    <table class="tala-data-table tala-profile-table tala-profile-results">
                        <caption class="sr-only">Released subject results. Select a subject to see its context and next step.</caption>
                        <thead><tr><th scope="col">Subject</th><th scope="col">Units</th><th scope="col">Final result</th></tr></thead>
                        <tbody>@foreach ($results as $result)
                            <tr @class(['tala-profile-row-selected' => $selected && $selected['course_enrollment']->id === $result['course_enrollment']->id]) wire:key="academic-result-{{ $result['course_enrollment']->id }}">
                                <th scope="row"><button type="button" wire:click="selectAcademicResult({{ $result['course_enrollment']->id }})" aria-pressed="{{ $selected && $selected['course_enrollment']->id === $result['course_enrollment']->id ? 'true' : 'false' }}" class="tala-profile-row-action"><strong>{{ $result['course_specification']?->title ?? 'Recorded subject' }}</strong><span class="tala-profile-muted">{{ $result['course_specification']?->course?->code }}</span></button></th>
                                <td class="tala-profile-numeric">{{ $result['units'] === '' ? 'Not recorded' : $result['units'] }}</td>
                                <td><strong class="tala-profile-result-value">{{ $result['result'] }}</strong>@if ($result['result'] === 'INC')<span class="tala-profile-muted">Incomplete</span>@endif</td>
                            </tr>
                        @endforeach</tbody>
                    </table>
                </div>
                <x-record-collection-footer :paginator="$results" method="setAcademicPage" label="results" />
            </div>
            <aside class="tala-profile-ledger__context" aria-label="Selected subject">
                @if ($selected)
                    <div><span class="tala-profile-eyebrow">Selected subject</span><h3>{{ $selected['course_specification']?->course?->code }} &middot; {{ $selected['course_specification']?->title }}</h3></div>
                    @if ($selected['result'] === 'INC')
                        <dl class="tala-profile-context-facts"><div><dt>Completion deadline</dt><dd>{{ $selected['inc_deadline']?->format('M j, Y') ?? 'Not recorded' }}</dd></div><div><dt>Remaining work</dt><dd>{{ $selected['event']?->inc_completion_note ?: 'Ask the designated Faculty about the remaining requirement.' }}</dd></div></dl>
                        @if ($selected['followUp'] ?? null)
                            <x-inc-follow-up :context="$selected['followUp']" :show-deadline="false" compact />
                        @endif
                        @if ($selected['followUpUrl'])<x-filament::button tag="a" :href="$selected['followUpUrl']" class="tala-profile-full-action">Open INC follow-up</x-filament::button>@endif
                    @else
                        <dl class="tala-profile-context-facts"><div><dt>Released result</dt><dd class="tala-profile-figure">{{ $selected['result'] }}</dd></div></dl>
                        @if ($selected['average_exclusion'])<p class="tala-profile-muted">{{ $selected['average_exclusion'] }}</p>@endif
                    @endif
                    @if ($selected['sourceUrl'])<x-filament::button tag="a" color="gray" :href="$selected['sourceUrl']" icon="heroicon-o-arrow-top-right-on-square" class="tala-profile-full-action">View class record</x-filament::button>@endif
                @else
                    <p class="tala-profile-muted">Select a subject to see its recorded result and next step.</p>
                @endif
            </aside>
        </div>
    @else
        <x-filament::empty-state :heading="trim($this->academicSearch) !== '' ? 'No matching subjects' : 'No released grades yet'" :description="trim($this->academicSearch) !== '' ? 'Try another subject name or code, or choose a different term.' : 'Results appear here after the Registrar releases the class roster.'" icon="heroicon-o-document-chart-bar" compact :contained="false" />
    @endif
</section>
@if ($curriculum && count($curriculum['required']))
    <section class="tala-profile-ledger" aria-label="Curriculum progress">
        <header class="tala-profile-ledger__toolbar">
            <div>
                <h2>Curriculum progress</h2>
                <p class="tala-profile-muted">{{ $curriculum['completed_units'] }} units completed or credited across terms.</p>
            </div>
            @if ($returnEnrollmentUrl)
                <x-filament::link :href="$returnEnrollmentUrl" icon="heroicon-o-arrow-left">Return to enrollment case</x-filament::link>
            @elseif ($profile['enrollmentUrl'] ?? null)
                <x-filament::link :href="$profile['enrollmentUrl']" icon="heroicon-o-clipboard-document-check">View current enrollment</x-filament::link>
            @endif
            @if ($this->correctCreditingAction->isVisible())
                {{ $this->correctCreditingAction }}
            @endif
        </header>
        <div style="display: grid; gap: 1.25rem;">
            @foreach ($curriculumGroups as $yearLabel => $termGroups)
                @php
                    $yearRows = collect($termGroups)->flatten(1);
                    $yearDone = $yearRows->whereIn('status', ['Completed', 'Approved credit'])->count();
                @endphp
                <section aria-label="{{ $yearLabel }} curriculum progress" style="display: grid; gap: .75rem;">
                    <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .25rem 1rem;">
                        <h3 style="margin: 0; font-size: 1rem; font-weight: 600;">{{ $yearLabel }}</h3>
                        <p class="tala-profile-muted" style="margin: 0;">{{ $yearDone }} of {{ $yearRows->count() }} {{ $yearRows->count() === 1 ? 'subject' : 'subjects' }} completed</p>
                    </div>
                    @foreach ($termGroups as $termLabel => $rows)
                        <div class="tala-data-table-scroll tala-profile-table-wrap" role="region" aria-label="{{ $yearLabel }} · {{ $termLabel }} required subjects" tabindex="0">
                            <table class="tala-data-table tala-profile-table">
                                <caption style="text-align: left; font-size: .8125rem; font-weight: 600; color: var(--tala-muted, #52525b); padding: .5rem .75rem;">{{ $termLabel }}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Required subject</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Credited units</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        @php
                                            $activeReqs = ($row['curriculum_entry']->courseSpecification?->requirements ?? collect())
                                                ->filter(fn (\App\Models\CourseRequirement $req): bool => $req->state === \App\Models\CourseRequirement::StateActive
                                                    && in_array($req->rule_type, [\App\Models\CourseRequirement::TypePrerequisite, \App\Models\CourseRequirement::TypeCorequisite], true))
                                                ->groupBy(fn (\App\Models\CourseRequirement $req): string => $req->rule_type.':'.$req->group_key);
                                            $rowDone = in_array($row['status'], ['Completed', 'Approved credit'], true);
                                        @endphp
                                        <tr>
                                            <th scope="row">
                                                <div>{{ $row['curriculum_entry']->courseSpecification?->course?->code }} &middot; {{ $row['curriculum_entry']->courseSpecification?->title }}</div>
                                                @if ($activeReqs->isNotEmpty())
                                                    <div class="tala-profile-muted" style="font-weight: 400; font-size: .8125rem; margin-block-start: .2rem;">
                                                        @foreach ($activeReqs as $reqGroup)
                                                            @php
                                                                $ruleType = $reqGroup->first()?->rule_type;
                                                                $typeLabel = \App\Models\CourseRequirement::typeOptions()[$ruleType] ?? 'Requirement';
                                                                $codes = $reqGroup->pluck('relatedCourse.code')->filter()->implode(' or ');
                                                                $groupSatisfied = $reqGroup->contains(fn (\App\Models\CourseRequirement $req): bool => $satisfiedCourseIds->contains((int) $req->related_course_id));
                                                            @endphp
                                                            <span style="display: block;">
                                                                {{ $typeLabel }}: {{ $codes !== '' ? $codes : 'Subject not recorded' }}@if (! $rowDone) · {{ $groupSatisfied ? 'Satisfied' : 'Unmet' }}@endif
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </th>
                                            <td>{{ str($row['status'])->headline() }}</td>
                                            <td class="tala-profile-numeric">{{ $row['credited_units'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </section>
            @endforeach
        </div>
    </section>
@else
    <p class="tala-profile-source-note"><x-filament::icon icon="heroicon-o-information-circle" />Curriculum progress unavailable. {{ $student->curriculumVersion ? 'This curriculum has no recorded subjects to evaluate.' : 'No curriculum is recorded for this student.' }}</p>
@endif
@if ($profile['externalRequirements']->isNotEmpty())
    <x-filament::section compact>
        <x-slot:heading>External assessments</x-slot:heading>
        <x-slot:description>Verified results for qualifications listed in the student’s active curriculum.</x-slot:description>
        <dl class="tala-profile-context-facts">
            @foreach ($profile['externalRequirements'] as $requirement)
                <div>
                    <dt>{{ $requirement->qualification_label }}</dt>
                    <dd>{{ \App\Models\ExternalCompetencyResult::outcomeLabel($requirement->currentResult?->outcome) }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($this->recordExternalCompetencyAction->isVisible())<x-slot:footer>{{ $this->recordExternalCompetencyAction }}</x-slot:footer>
        @endif
    </x-filament::section>
@endif
@if ($this->recordAcademicDecisionAction->isVisible())
    <div class="tala-profile-context-action">{{ $this->recordAcademicDecisionAction }}<p class="tala-profile-muted">Use only for an approved academic exception or restriction.</p></div>
@endif
