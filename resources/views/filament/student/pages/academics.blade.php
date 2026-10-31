<x-filament-panels::page>
    @assets
        <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
    @endassets
    @if (! $student)
        <x-filament::section heading="Student record unavailable"><p>Contact the Registrar to link your student record.</p></x-filament::section>
    @else
        <div @class(['student-academics student-academic-reading', 'student-academic-current' => $academicView === 'current'])>
            @if ($academicView === 'current')
                @include('filament.components.student-study-context', [
                    'contextProgram' => $studyContext['program_name'] ?? 'Program unavailable',
                    'contextFacts' => $studyContext['is_official'] ? [
                        'Year level' => $studyContext['study_level_label'],
                        'Term' => $studyContext['semester'] ?? 'Unavailable',
                        'School year' => $studyContext['academic_year'] ?? 'Unavailable',
                    ] : [],
                    'contextNote' => $studyContext['is_official'] ? 'Year level follows your current subjects.' : null,
                ])
                @if ($effect['effect'] !== 'ALLOWED')
                    <section class="student-academic-notice" aria-label="Enrollment guidance"><h2>Next enrollment guidance</h2><p>{{ $effect['reason'] }}</p><p>Contact the Registrar about your next enrollment.</p><x-filament::link :href="$enrollmentUrl">View enrollment</x-filament::link></section>
                @endif
                <div class="student-academic-reading-grid">
                    <section class="student-record-panel tala-task-surface" aria-labelledby="current-subjects-heading">
                        <div class="student-academic-heading"><h2 class="student-record-heading" id="current-subjects-heading"><x-filament::icon icon="heroicon-o-book-open" />Current subjects</h2>@if ($studyContext['is_official'])<p>{{ $currentResults->count() }} {{ str('subject')->plural($currentResults->count()) }} · {{ (float) $currentUnits }} units</p>@endif</div>
                        @if ($currentResults->isNotEmpty())
                            <div class="student-academic-table-wrap" role="region" aria-label="Current subjects and results" tabindex="0">
                                <table class="student-academic-table">
                                    <thead><tr><th scope="col">Subject</th><th scope="col">Units</th><th scope="col">Result</th></tr></thead>
                                    <tbody>@foreach ($currentResults as $result)
                                        <tr><th scope="row">{{ $result['course_specification']?->title ?? 'Subject unavailable' }}<span class="student-academic-secondary">{{ $result['course_specification']?->course?->code }}</span></th><td class="student-academic-number">{{ (float) $result['units'] }}</td><td><x-student-academic-result :$result /></td></tr>
                                    @endforeach</tbody>
                                </table>
                            </div>
                            <p class="student-academic-note">Results appear after Registrar release.</p>
                            @if ($currentAverage)
                                @if ($currentAverage['value'] !== null)<p class="student-academic-average">{{ $currentAverage['label'] }} <strong>{{ $currentAverage['value'] }}</strong></p>
                                @else<details class="student-academic-source"><summary>About your average</summary><p>{{ $currentAverage['reason'] }}</p>@if ($cumulative['value'] === null)<p>No released results currently count toward the cumulative GWA.</p>@endif</details>@endif
                            @endif
                        @else
                            @if ($studyContext['is_official'])<p class="student-academic-empty">No active subjects are recorded in your current registration. Contact the Registrar if this looks incorrect.</p>
                            @else<div class="student-academic-next"><p>Your current subjects appear after official enrollment.</p><x-filament::button tag="a" :href="$enrollmentUrl">View enrollment</x-filament::button></div>@endif
                        @endif
                    </section>
                    <aside class="student-academic-rail" aria-label="Study plan and dates">
                        <section class="student-record-panel tala-task-surface"><h2 class="student-record-heading"><x-filament::icon icon="heroicon-o-map" />Study plan</h2>@if ((float) $curriculum['completed_units'] > 0)<p class="student-academic-total"><strong>{{ (float) $curriculum['completed_units'] }}</strong><span>Units completed</span></p>@else<p class="student-academic-note">Explore the subjects required by your curriculum. Completed results appear after release.</p>@endif<x-filament::button tag="a" color="gray" :href="static::getUrl(['view' => 'plan'], panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">View study plan</x-filament::button></section>
                        @if ($cumulative['value'] !== null)<section class="student-record-panel tala-task-surface"><h2>Cumulative average</h2><p class="student-academic-total"><strong>{{ $cumulative['value'] }}</strong></p><p class="student-academic-note">Through {{ $cumulative['through'] }}</p></section>@endif
                        @if ($studyContext['is_official'] || $examinationPeriod['status'] === 'Available')
                        <section class="student-record-panel tala-task-surface" aria-labelledby="academic-exams-heading"><h2 class="student-record-heading" id="academic-exams-heading"><x-filament::icon icon="heroicon-o-calendar-days" />Exam dates</h2>
                            @if (! $studyContext['is_official'] && $examinationPeriod['term'])<p class="student-academic-note">Recorded for {{ $examinationPeriod['term'] }}</p>@endif
                            @if ($examinationPeriod['status'] === 'Available')<p>{{ $examinationPeriod['opens_on']->format('M j, Y') }}–{{ $examinationPeriod['closes_on']->format('M j, Y') }}</p><details class="student-academic-source"><summary>Calendar details</summary><p>{{ $examinationPeriod['authority_reference'] ?: 'Authority not recorded' }} · {{ $examinationPeriod['owner'] }}</p><p>{{ $examinationPeriod['message'] }}</p></details>
                            @else<p class="student-academic-note">Dates unavailable. Check with the Registrar.</p>@endif
                        </section>
                        @endif
                    </aside>
                </div>
            @elseif ($academicView === 'plan')
                <p class="student-academic-program">{{ $studyContext['program_name'] ?? 'Program unavailable' }}</p>
                @if ($curriculum['required'] !== [])
                    @php($inProgressCount = collect($curriculum['required'])->where('status', 'In progress')->count())
                    @if ((float) $curriculum['completed_units'] > 0 || $inProgressCount > 0)
                        <dl class="student-plan-figures"><div><dt>Units completed</dt><dd>{{ (float) $curriculum['completed_units'] }}</dd></div><div><dt>Subjects in progress</dt><dd>{{ $inProgressCount }}</dd></div></dl>
                    @else
                        <section class="student-plan-start"><h2>{{ $studyContext['is_official'] ? 'No completed units recorded' : 'These are your planned subjects' }}</h2><p>{{ $studyContext['is_official'] ? 'Released passing results and approved credits will update this plan.' : 'Check Enrollment for the subjects being prepared. They become current subjects after official enrollment.' }}</p><x-filament::link :href="$enrollmentUrl">View enrollment</x-filament::link></section>
                    @endif
                    @if ($effect['effect'] !== 'ALLOWED' || collect($curriculum['required'])->contains('status', 'Deficient'))
                        <section class="student-academic-notice" aria-label="Study plan guidance"><h2>Registrar advising needed</h2><p>{{ $effect['effect'] !== 'ALLOWED' ? $effect['reason'] : 'A deficient result remains in your plan.' }}</p><p>Ask the Registrar about your next subject selection.</p><x-filament::link :href="$enrollmentUrl">Review enrollment</x-filament::link></section>
                    @endif
                    <div class="student-plan-map">
                        @foreach ($curriculumGroups as $year => $termGroups)
                            <section class="student-plan-year" aria-label="{{ ctype_digit((string) $year) ? 'Year '.$year : $year }}">
                                <h2>{{ ctype_digit((string) $year) ? 'Year '.$year : $year }}</h2>
                                <div>@foreach ($termGroups as $termLabel => $rows)
                                    <section class="student-plan-term"><h3>{{ $termLabel ?: 'Term not recorded' }}</h3>
                                        <ul class="student-plan-subjects">@foreach ($rows as $row)
                                            <li><div><h4>{{ $row['curriculum_entry']->courseSpecification?->title ?? 'Subject unavailable' }}</h4><p class="student-academic-secondary">{{ $row['curriculum_entry']->courseSpecification?->course?->code }}</p>
                                                @if (! in_array($row['status'], ['Completed', 'Approved credit'], true))
                                                    @foreach ($planRequirements->get($row['curriculum_entry']->id) ?? [] as $requirement)<p class="student-academic-secondary">{{ \App\Models\CourseRequirement::typeOptions()[$requirement->rule_type] }}: {{ $requirement->relatedCourse?->code ?? 'Subject not recorded' }}@if ($requirement->minimum_grade !== null) · Minimum grade {{ (float) $requirement->minimum_grade }}@endif</p>@endforeach
                                                    @if ($row['status'] === 'Deficient')<p class="student-academic-secondary">Ask the Registrar about completing or retaking this subject.</p>@endif
                                                @endif
                                            </div><p>{{ (float) $row['curriculum_entry']->courseSpecification?->credit_units }} units</p><p @class(['student-plan-status', 'student-plan-status-future' => $row['status'] === 'Not taken'])><x-filament::icon :icon="match ($row['status']) { 'Completed', 'Approved credit' => 'heroicon-o-check-circle', 'In progress' => 'heroicon-o-clock', 'Deficient' => 'heroicon-o-exclamation-circle', default => 'heroicon-o-minus-circle' }" />{{ $row['status'] === 'Not taken' ? 'Not yet enrolled' : $row['status'] }}</p></li>
                                        @endforeach</ul>
                                    </section>
                                @endforeach</div>
                            </section>
                        @endforeach
                    </div>
                    <p class="student-academic-note">Completed units include released passing results and approved credits. The Registrar maintains your curriculum evaluation and confirms your next subject selection.</p>
                @else<p class="student-academic-empty">Your curriculum is unavailable. Contact the Registrar.</p>@endif
            @elseif ($academicView === 'record')
                <p class="student-academic-program">{{ $studyContext['program_name'] ?? 'Program unavailable' }}</p>
                @if ($results->whereNotNull('event')->isEmpty() && $historicalResults->isEmpty())
                    <section class="student-record-empty"><x-filament::icon icon="heroicon-o-document-text" /><h2>Your results will appear here after release.</h2><p>You can check enrolled subjects in Academics.</p><x-filament::button tag="a" color="gray" :href="static::getUrl(panel: 'student')" icon="heroicon-m-arrow-right" icon-position="after">View current subjects</x-filament::button></section>
                    <p class="student-academic-note">An unofficial record becomes available after results are released.</p>
                @else
                    @if ($cumulative['value'] !== null)<p class="student-record-gwa">Cumulative average <strong>{{ $cumulative['value'] }}</strong><span>Through {{ $cumulative['through'] }}</span></p>@endif
                    <div class="student-record-terms">@foreach ($recordTerms as $term)
                        @php($average = $termAverages->get($term->id))
                        <section class="student-record-term"><h2>@if ($termYearLevels->get($term->id)){{ $termYearLevels->get($term->id) }} · @endif{{ $term->label }}@if ($term->academicYear) · {{ $term->academicYear->label }}@endif</h2><p class="student-academic-note">{{ $average['label'] }}: {{ $average['value'] ?? $average['reason'] }}</p>
                            <div class="student-academic-table-wrap" role="region" aria-label="{{ $term->label }} results" tabindex="0"><table class="student-academic-table"><thead><tr><th scope="col">Subject</th><th scope="col">Units</th><th scope="col">Result</th></tr></thead><tbody>
                                @foreach ($results->where('term.id', $term->id) as $result)<tr><th scope="row">{{ $result['course_specification']?->title }}<span class="student-academic-secondary">{{ $result['course_specification']?->course?->code }}</span></th><td>{{ (float) $result['units'] }}</td><td><x-student-academic-result :$result /></td></tr>@endforeach
                            </tbody></table></div>
                        </section>
                    @endforeach</div>
                @endif
                @if ($competencyResults->isNotEmpty())<section class="student-record-section"><h2>External competency results</h2>@foreach ($competencyResults as $result)<p>{{ $result->requirement?->qualification_label ?? $result->requirement?->requirement_code }} · {{ str($result->outcome)->replace('_', ' ')->headline() }} · {{ $result->is_current ? 'Current' : 'Superseded' }} · {{ $result->recorded_at?->format('M j, Y') }}</p>@endforeach</section>@endif
                @if ($lifecycleChanges->isNotEmpty())<section class="student-record-section"><h2>Student record changes</h2>@foreach ($lifecycleChanges as $change)<p>{{ str($change->type)->replace('_', ' ')->headline() }} · {{ str($change->state)->replace('_', ' ')->headline() }} · {{ $change->effective_on?->format('M j, Y') }}</p>@endforeach</section>@endif
            @else
                @php($earlierConferrals = $degreeConferrals->reject(fn (\App\Models\DegreeConferral $conferral): bool => $conferral->is($completion['conferral'])))
                <div class="student-graduation-records">
                @if ($completion['conferral'])
                    <section class="student-degree-summary"><h2>{{ $completion['conferral']->degree_name }}</h2><p>Conferred {{ $completion['conferral']->conferred_on->format('M j, Y') }}</p><p class="student-academic-note">Recorded by the Registrar {{ $completion['conferral']->recorded_at?->format('M j, Y') }}.</p></section>
                @endif
                @if ($earlierConferrals->isNotEmpty())
                    <details class="learner-disclosure"><summary>Earlier degree records</summary><div><ol class="student-record-timeline">@foreach ($earlierConferrals as $conferral)<li><strong>{{ $conferral->degree_name }}</strong><time datetime="{{ $conferral->conferred_on->toDateString() }}">{{ $conferral->conferred_on->format('M j, Y') }}</time></li>@endforeach</ol></div></details>
                @endif
                @if ($graduationApplications->isNotEmpty())
                    <section class="student-record-section"><h2>Application history</h2><ol class="student-record-timeline">@foreach ($graduationApplications as $application)<li><time datetime="{{ $application->applied_at->toDateString() }}">{{ $application->applied_at->format('M j, Y') }}</time><strong>{{ match ($application->state) { \App\Models\GraduationApplication::StateActive => 'On file', \App\Models\GraduationApplication::StateCorrected => 'Corrected record', default => str($application->state)->replace('_', ' ')->headline() } }}</strong></li>@endforeach</ol></section>
                @endif
                <p class="student-academic-note">These records are maintained by the Registrar. Contact the Registrar about corrections.</p>
                @if ($this->resendCompletionUpdateAction->isVisible()){{ $this->resendCompletionUpdateAction }}@endif
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
