<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $roster = $membership['roster'] ?? null;
        $designated = $roster !== null && (int) $roster->teachingAssignment?->faculty_user_id === (int) auth()->id();
        $designatedName = $roster?->teachingAssignment?->faculty?->name ?? $roster?->faculty?->name ?? 'assigned';
        $isStudentListFiltered = trim($studentSearch) !== '' || $needsChangesOnly;
    @endphp

    @if ($roster === null)
        <section class="faculty-class-worklist tala-faculty-workflow space-y-4" aria-label="Assigned classes">
            @if ($classTerms->isNotEmpty())
                <x-record-scope-bar class="faculty-class-scope" label="Filter assigned classes">
                    <div class="faculty-class-scope-fields">
                        <div class="faculty-class-scope-field">
                            <label for="faculty-class-term">Academic year and term</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="faculty-class-term" wire:model.live="classTerm">
                                    <option value="">All assigned terms</option>
                                    @if ($classTermFilter > 0 && ! $classTerms->contains('id', $classTermFilter))
                                        <option value="{{ $classTermFilter }}" selected>Term no longer assigned</option>
                                    @endif
                                    @foreach ($classTerms as $classTermOption)
                                        <option value="{{ $classTermOption->id }}">{{ $classTermOption->academicYear?->label }} · {{ $classTermOption->label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div class="faculty-class-scope-field">
                            <label for="faculty-class-queue">Class queue</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="faculty-class-queue" wire:model.live="queue">
                                    <option value="all">All classes</option>
                                    <option value="needs-action">Needs action</option>
                                    <option value="inc">INC follow-up</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div class="faculty-class-scope-field faculty-class-scope-field--search">
                            <label for="faculty-class-search">Find a class</label>
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                                <x-filament::input id="faculty-class-search" type="search" wire:model.live.debounce.300ms="classSearch" placeholder="Course title or class code" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>
                </x-record-scope-bar>
            @endif

            @forelse ($classRows->groupBy(fn (array $classRow): int => $classRow['roster']->termOffering->term_id) as $termId => $termRows)
                @php
                    $groupTerm = $termRows->first()['roster']->termOffering->term;
                @endphp
                <section class="faculty-class-term-group" aria-labelledby="faculty-class-term-{{ $termId }}">
                    <header>
                        <h2 id="faculty-class-term-{{ $termId }}">
                            <x-filament::icon icon="heroicon-o-calendar-days" />
                            <span>{{ $groupTerm->academicYear?->label }} · {{ $groupTerm->label }}</span>
                        </h2>
                        <span>{{ $termRows->count() }} shown</span>
                    </header>
                    <ul class="faculty-class-grid">
                        @foreach ($termRows as $classRow)
                            @php
                                $classRoster = $classRow['roster'];
                                $course = $classRoster->termOffering?->curriculumEntry?->courseSpecification;
                                $cardWindow = $classRow['entryWindow'] ?? null;
                                $rosterStatus = $classRow['current'] ? match ($classRoster->state) {
                                    \App\Models\GradeRoster::StateReturned => 'Changes requested',
                                    \App\Models\GradeRoster::StateReleased => 'Released',
                                    \App\Models\GradeRoster::StateSubmitted => 'Submitted for Registrar review',
                                    \App\Models\GradeRoster::StateLateNotSubmitted => 'Submission overdue',
                                    default => 'Draft',
                                } : 'Class list needs update';
                                $rosterColor = $classRow['current'] ? match ($classRoster->state) {
                                    \App\Models\GradeRoster::StateReturned, \App\Models\GradeRoster::StateLateNotSubmitted => 'warning',
                                    \App\Models\GradeRoster::StateReleased => 'success',
                                    \App\Models\GradeRoster::StateSubmitted => 'info',
                                    default => 'gray',
                                } : 'warning';
                                $dateCue = null;
                                if ($cardWindow && $cardWindow['is_open'] && $cardWindow['closes_at']) {
                                    $dateCue = 'Closes '.$cardWindow['closes_at']->copy()->timezone('Asia/Manila')->format('M j');
                                } elseif ($classRoster->state === \App\Models\GradeRoster::StateReleased && $classRoster->released_at) {
                                    $dateCue = 'Released '.$classRoster->released_at->copy()->timezone('Asia/Manila')->format('M j');
                                } elseif ($classRoster->state === \App\Models\GradeRoster::StateSubmitted && $classRoster->submitted_at) {
                                    $dateCue = 'Submitted '.$classRoster->submitted_at->copy()->timezone('Asia/Manila')->format('M j');
                                } elseif ($cardWindow && $cardWindow['closes_at']) {
                                    $dateCue = 'Closed '.$cardWindow['closes_at']->copy()->timezone('Asia/Manila')->format('M j');
                                }
                            @endphp
                            <li>
                                <a class="faculty-class-card tala-task-surface" {{ \Filament\Support\generate_href_html($this::getUrl(['roster' => $classRoster->id])) }}>
                                    <div class="faculty-class-card-identity">
                                        <h3>{{ $course?->title }}</h3>
                                        <p>{{ collect([$course?->course?->code, $classRoster->section?->code])->filter()->implode(' · ') }}</p>
                                    </div>
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="faculty-class-card-arrow" />
                                    <div class="faculty-class-card-summary">
                                        <x-filament::badge :color="$rosterColor">{{ $rosterStatus }}</x-filament::badge>
                                        @if ($classRow['current'])
                                            <span class="faculty-class-card-progress">
                                                @if ($classRow['count'] > 0)
                                                    {{ $classRow['graded'] }} of {{ $classRow['count'] }} graded
                                                @else
                                                    0 students
                                                @endif
                                                @if (($classRow['openIncCount'] ?? 0) > 0)
                                                    · {{ $classRow['openIncCount'] }} open INC
                                                @endif
                                                @if ($dateCue)
                                                    · {{ $dateCue }}
                                                @endif
                                            </span>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                @php
                    $emptyHeading = match (true) {
                        $classTerms->isEmpty() => 'No assigned classes yet',
                        $classPaginator->total() > 0 => 'No classes on this page',
                        $queue === 'inc' && $classTermFilter === 0 && $classSearch === '' => 'No open INC follow-ups',
                        $queue === 'needs-action' && $classTermFilter === 0 && $classSearch === '' => 'No classes need action',
                        default => 'No classes match your filters',
                    };
                    $emptyDescription = match (true) {
                        $classTerms->isEmpty() => 'Your classes will appear here after the Registrar sets up the term and teaching assignments. Expecting a class? Ask the Registrar to check your teaching assignment.',
                        $classPaginator->total() > 0 => 'The class list has changed. Return to the first page to continue.',
                        $queue === 'inc' && $classTermFilter === 0 && $classSearch === '' => 'None of your released classes currently have open Faculty INC completions.',
                        $queue === 'needs-action' && $classTermFilter === 0 && $classSearch === '' => 'You have no returned, overdue, or open draft classes right now.',
                        default => 'Try another course title, class code, queue or term.',
                    };
                @endphp
                <x-servitech-empty-state :illustrated="$classTerms->isEmpty()" :heading="$emptyHeading" :description="$emptyDescription" icon="heroicon-o-magnifying-glass">
                    @if ($classTerms->isEmpty())
                        <x-filament::button tag="a" :href="$this::getUrl(['classes' => 1])" color="gray" icon="heroicon-m-arrow-path">Refresh classes</x-filament::button>
                    @elseif ($classPaginator->total() > 0)
                        <x-filament::button tag="a" :href="$classPaginator->url(1)" color="gray">Show first page</x-filament::button>
                    @elseif ($hasActiveClassFilters)
                        <x-filament::button type="button" color="gray" wire:click="clearClassFilters">{{ $queue !== 'all' && $classTermFilter === 0 && $classSearch === '' ? 'Show all classes' : 'Clear filters' }}</x-filament::button>
                    @endif
                </x-servitech-empty-state>
            @endforelse

            @if ($classRows->isNotEmpty())
                <footer class="faculty-class-pagination">
                    <p>{{ $classPaginator->firstItem() }}–{{ $classPaginator->lastItem() }} of {{ $classPaginator->total() }} {{ str('class')->plural($classPaginator->total()) }}</p>
                    @if ($classPaginator->hasPages())
                        <nav aria-label="Class pages">
                            @if ($classPaginator->onFirstPage())
                                <x-filament::button color="gray" icon="heroicon-m-chevron-left" disabled>Previous</x-filament::button>
                            @else
                                <x-filament::button tag="a" color="gray" icon="heroicon-m-chevron-left" :href="$classPaginator->previousPageUrl()" rel="prev">Previous</x-filament::button>
                            @endif
                            <span>Page {{ $classPaginator->currentPage() }} of {{ $classPaginator->lastPage() }}</span>
                            @if ($classPaginator->hasMorePages())
                                <x-filament::button tag="a" color="gray" icon="heroicon-m-chevron-right" icon-position="after" :href="$classPaginator->nextPageUrl()" rel="next">Next</x-filament::button>
                            @else
                                <x-filament::button color="gray" icon="heroicon-m-chevron-right" icon-position="after" disabled>Next</x-filament::button>
                            @endif
                        </nav>
                    @endif
                </footer>
            @endif
        </section>
    @elseif (!$membership['isCurrent'])
        <section class="faculty-membership-message" role="status">
            <h2>Class membership needs an update</h2>
            <p>Ask the Registrar to refresh this class list, then reload this page. Student list and exports will be available once the Registrar updates the class list.</p>
        </section>
    @else
        @if ($roster->state === \App\Models\GradeRoster::StateSubmitted)
            <div class="faculty-class-handoff">
                <x-workflow-handoff current-owner="Registrar" current-area="Class grades · Review" current-task="Review the submitted class grades and release them, or return specific results for changes." next-owner="Student" next-area="Academics · Released grades" next-task="See official results after Registrar release." />
            </div>
        @elseif ($roster->state === \App\Models\GradeRoster::StateReturned && $entryWindow['is_open'])
            <div class="faculty-class-handoff">
                <x-workflow-handoff current-owner="Designated Faculty" current-area="Class grades · Changes requested" current-task="Update the results returned by Registrar, then resubmit the class grades." next-owner="Registrar" next-area="Class grades · Review" next-task="Review the resubmitted class grades." />
            </div>
        @elseif (!$entryWindow['is_open'] && $roster->state !== \App\Models\GradeRoster::StateReleased)
            <div class="faculty-class-handoff">
                <x-workflow-handoff current-owner="Registrar" current-area="Class grades · Entry access" :current-task="$entryWindow['basis'].'. Coordinate the Grade Entry calendar or a late-entry authorization.'" />
            </div>
        @endif

        <form wire:submit="saveDraft" class="faculty-result-workspace" aria-label="Class result workspace"
            x-data="{
                pending: false, editRevision: 0, transportFailed: false, unsubscribe: null,
                init() {
                    this.unsubscribe = $wire.interceptRequest(({ onFailure, onError, onSuccess }) => {
                        const revision = this.editRevision;
                        onFailure(() => { if (this.pending) this.transportFailed = true; });
                        onError(() => { if (this.pending) this.transportFailed = true; });
                        onSuccess(() => { if (this.editRevision === revision && !$wire.draftSaveError) this.pending = false; });
                    });
                },
                destroy() { this.unsubscribe?.(); }
            }"
            x-on:input="if ($event.target.closest('.faculty-result-fields')) { pending = true; editRevision++ }"
            x-on:change="if ($event.target.closest('.faculty-result-fields')) { pending = true; editRevision++ }"
            x-on:roster-draft-saved.window="transportFailed = false"
            x-on:beforeunload.window="if (pending || transportFailed || $wire.draftSaveError) { $event.preventDefault(); $event.returnValue = ''; }"
            >
            <x-record-workspace>
            <x-slot:browser class="faculty-result-students" aria-labelledby="faculty-students-heading">
                <x-record-collection-controls :heading="'Students ('.$workspace['total'].')'">
                    <x-slot:search>
                        <label for="faculty-student-search" class="sr-only">Find a student</label>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input id="faculty-student-search" type="search" wire:model.live.debounce.300ms="studentSearch" placeholder="Name or student number" />
                        </x-filament::input.wrapper>
                    </x-slot:search>
                    @if ($roster->state === \App\Models\GradeRoster::StateReturned)
                        <label class="faculty-result-filter">
                            <x-filament::input.checkbox wire:model.live="needsChangesOnly" />
                            <span>Only results needing changes ({{ $workspace['returned'] }})</span>
                        </label>
                    @endif
                    @if ($isStudentListFiltered)
                        <p class="faculty-result-list-count" aria-live="polite">{{ $workspace['filtered'] }} of {{ $workspace['total'] }} students shown</p>
                    @endif
                </x-record-collection-controls>
                <div id="faculty-student-browser" class="faculty-result-browse">
                    <ul class="tala-record-list faculty-result-student-list" aria-label="Students in this class" wire:loading.class="faculty-result-loading" wire:target="studentSearch,needsChangesOnly,goToStudentPage">
                        @forelse ($workspace['students'] as $student)
                            <li wire:key="roster-student-{{ $roster->id }}-{{ $student['id'] }}">
                                <x-record-option wire:click="selectStudent({{ $student['id'] }})" aria-controls="faculty-result-inspector" :selected="$student['id'] === $selectedStudentId" :result="$student['final_result']">
                                    <strong>{{ $student['legal_name'] }}</strong><small>{{ $student['student_number'] }}</small>
                                    @if ($student['returned'])<x-filament::badge color="warning" icon="heroicon-m-arrow-uturn-left">Changes requested</x-filament::badge>@endif
                                </x-record-option>
                            </li>
                        @empty
                            <li>
                                <x-servitech-empty-state :heading="$needsChangesOnly && trim($studentSearch) === '' ? 'No changes requested' : 'No matching students'" :description="$needsChangesOnly && trim($studentSearch) === '' ? 'Show all students to review the saved class results.' : 'Try another name or student number, or clear the filter.'" icon="heroicon-o-magnifying-glass" />
                            </li>
                        @endforelse
                    </ul>
                </div>
            </x-slot:browser>
            <x-slot:collectionFooter>
                <x-record-collection-footer label="students" method="goToStudentPage" :page-state="['from' => $workspace['from'], 'to' => $workspace['to'], 'total' => $workspace['filtered'], 'page' => $workspace['page'], 'lastPage' => $workspace['lastPage']]" />
            </x-slot:collectionFooter>
            <x-slot:inspector id="faculty-result-inspector" class="faculty-result-editor" aria-labelledby="faculty-result-heading">
                @if ($workspace['selected'] !== null)
                    @php $selected = $workspace['selected']; @endphp
                    @if ($workspace['filtered'] > 1)
                        <nav class="tala-record-navigation" aria-label="Student selection">
                            <span>Student {{ $workspace['position'] }} of {{ $workspace['filtered'] }}{{ $needsChangesOnly ? ' needing changes' : '' }}</span>
                            <div class="tala-record-selection-navigation">
                                <x-filament::icon-button type="button" icon="heroicon-m-chevron-left" color="gray" label="Previous student" :wire:click="$workspace['previous'] !== null ? 'selectStudent('.$workspace['previous'].')' : null" :disabled="$workspace['previous'] === null" />
                                <x-filament::icon-button type="button" icon="heroicon-m-chevron-right" color="gray" label="Next student" :wire:click="$workspace['next'] !== null ? 'selectStudent('.$workspace['next'].')' : null" :disabled="$workspace['next'] === null" />
                            </div>
                        </nav>
                    @endif
                    <x-record-identity :name="$selected['legal_name']" :number="$selected['student_number']" :initials="$selected['initials']">
                        <x-slot:avatar>
                        <div class="student-profile-avatar faculty-result-avatar" aria-hidden="true" x-data="{ photoFailed: false }">
                            <span>{{ $selected['initials'] }}</span>
                            @if ($selected['photo_url'])<x-filament::avatar :src="$selected['photo_url']" alt="" size="lg" x-show="!photoFailed" x-on:error="photoFailed = true" />@endif
                        </div>
                        </x-slot:avatar>
                        <x-slot:heading id="faculty-result-heading" x-ref="selectedStudentHeading" tabindex="-1">{{ $selected['legal_name'] }}</x-slot:heading>
                    </x-record-identity>
                    @php
                        $incContext = $this->selectedIncContext();
                    @endphp
                    @if ($selected['returned'])
                        <div class="tala-review-feedback faculty-result-feedback">
                            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="size-5" />
                            <div><h3>Changes requested by Registrar</h3><p>{{ $selected['return_reason'] }}</p></div>
                        </div>
                    @endif
                    @if ($roster->state === \App\Models\GradeRoster::StateReleased)
                        <x-record-result :result="$selected['final_result']" :completion="$incContext" :description="$incContext ? 'Incomplete grade' : 'Official result'">
                            @if ($this->selectedStudentCanSubmitIncCompletion())
                                <x-slot:actions>
                                    {{ $this->submitIncCompletionAction }}
                                </x-slot:actions>
                            @endif
                        </x-record-result>
                        @if ($incContext)<x-inc-follow-up :context="$incContext" :show-deadline="false" />@endif
                    @else
                        <div class="faculty-result-fields" wire:key="student-result-{{ $selected['id'] }}">
                            {{ $this->form }}
                        </div>
                    @endif
                    @if (!$incContext)
                        @if (!$designated)
                            <div class="faculty-result-entry-state"><x-filament::icon icon="heroicon-o-lock-closed" class="size-5" /><p>View-only access. Only the designated faculty member ({{ $designatedName }}) can enter grades.</p></div>
                        @elseif ($this->canEditSelectedRoster() && !in_array($selected['id'], $editableRowIds, true))
                            <div class="faculty-result-entry-state"><x-filament::icon icon="heroicon-o-lock-closed" class="size-5" /><p>This result is unchanged. Only results returned by the Registrar can be edited.</p></div>
                        @endif
                    @endif
                @else
                    <div class="faculty-result-empty-editor">
                        <x-filament::icon icon="heroicon-o-user-group" class="size-8" />
                        <h2 id="faculty-result-heading">{{ $membership['count'] === 0 ? 'No students enrolled yet' : 'Choose a student' }}</h2>
                        <p>{{ $membership['count'] === 0 ? 'Officially enrolled students appear after the Registrar finalizes their enrollment.' : 'Use the student list to review a result. Searching and filtering keep your draft edits.' }}</p>
                    </div>
                @endif
            </x-slot:inspector>
            <x-slot:footer class="faculty-result-footer">
                @php
                    $missingFinalResults = (int) ($workspace['missingFinalResults'] ?? 0);
                    $missingIncNotes = (int) ($workspace['missingIncNotes'] ?? 0);
                    $rosterReadyToSubmit = $missingFinalResults === 0 && $missingIncNotes === 0 && ($workspace['total'] ?? 0) > 0;
                @endphp
                <div class="faculty-result-progress-copy">
                    <strong>{{ $workspace['recorded'] }} of {{ $workspace['total'] }} results recorded</strong>
                    @if ($workspace['returned'] > 0 || !$designated)<p>{{ $workspace['returned'] > 0 ? trans_choice('{1} :count result returned for changes|[2,*] :count results returned for changes', $workspace['returned']) : '' }}{{ $workspace['returned'] > 0 && !$designated ? ' · ' : '' }}{{ !$designated ? 'View-only · Designated faculty: '.$designatedName : '' }}</p>@endif
                </div>
                @if ($this->canEditSelectedRoster())
                    <div class="faculty-result-footer-actions">
                        <div role="status" aria-live="polite" class="faculty-result-save-state">
                            <span wire:offline>Offline · Changes are not saved. Keep this page open.</span>
                            <div wire:offline.class="hidden">
                                <span x-cloak x-show="transportFailed || $wire.draftSaveError"><span x-text="transportFailed ? 'Save not confirmed' : 'Not saved'"></span> · <span x-text="transportFailed ? 'Check your connection and retry.' : $wire.draftSaveError"></span></span>
                                <span x-show="!transportFailed && !$wire.draftSaveError" class="faculty-result-save-confirmation"><x-filament::icon icon="heroicon-m-check-circle" class="size-4" x-show="!pending" /><span x-cloak x-show="pending">Saving draft…</span><span x-show="!pending">{{ $workspace['hasUnsavedChanges'] ? 'Changes are not saved' : 'Draft saved' }}</span></span>
                                <x-filament::button type="button" color="gray" size="sm" x-cloak x-show="transportFailed || $wire.draftSaveError" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">Retry save</x-filament::button>
                            </div>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem;" role="status" aria-live="polite">
                            @if ($rosterReadyToSubmit)
                                <x-filament::badge color="success" icon="heroicon-m-check-circle">Ready to submit</x-filament::badge>
                            @else
                                @if ($missingFinalResults > 0)
                                    <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">{{ $missingFinalResults }} missing {{ str('result')->plural($missingFinalResults) }}</x-filament::badge>
                                @endif
                                @if ($missingIncNotes > 0)
                                    <x-filament::badge color="warning" icon="heroicon-m-document-text">{{ $missingIncNotes }} missing INC {{ str('note')->plural($missingIncNotes) }}</x-filament::badge>
                                @endif
                            @endif
                        </div>
                        {{ $this->submitAction }}
                    </div>
                    @if ($isStudentListFiltered)
                        <p class="faculty-result-submission-scope">Submission includes all {{ $workspace['total'] }} students, including those outside this view.</p>
                    @endif
                @endif
            </x-slot:footer>
            </x-record-workspace>
        </form>
    @endif
</x-filament-panels::page>
