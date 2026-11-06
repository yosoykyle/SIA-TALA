<x-filament-panels::page>
    @assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
    @php
        $roster = $membership['roster'] ?? null;
        $designated = $roster !== null && (int) $roster->teachingAssignment?->faculty_user_id === (int) auth()->id();
    @endphp

    @if ($roster === null)
        <section class="faculty-class-worklist" aria-label="Assigned classes">
            <p class="faculty-class-intro">Choose a class to record or review results.</p>

            @if ($classTerms->isNotEmpty())
                <form method="get" class="faculty-class-search">
                    <input type="hidden" name="classes" value="1">
                    <div>
                        <label for="faculty-class-term">Academic year and term</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="faculty-class-term" name="term">
                                <option value="">All assigned terms</option>
                                @if ($classTermFilter > 0 && ! $classTerms->contains('id', $classTermFilter))
                                    <option value="{{ $classTermFilter }}" selected>Term no longer assigned</option>
                                @endif
                                @foreach ($classTerms as $classTerm)
                                    <option value="{{ $classTerm->id }}" @selected($classTermFilter === $classTerm->id)>{{ $classTerm->academicYear?->label }} · {{ $classTerm->label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div>
                        <label for="faculty-class-search">Find a class</label>
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                            <x-filament::input id="faculty-class-search" name="q" type="search" :value="$classSearch" placeholder="Course title or class code" />
                        </x-filament::input.wrapper>
                    </div>
                    <x-filament::button type="submit" :color="$classRows->isEmpty() ? 'gray' : 'primary'">Find classes</x-filament::button>
                </form>
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
                                $rosterStatus = $classRow['current'] ? match ($classRoster->state) {
                                    \App\Models\GradeRoster::StateReturned => 'Changes requested',
                                    \App\Models\GradeRoster::StateReleased => 'Released',
                                    \App\Models\GradeRoster::StateSubmitted => 'Submitted',
                                    \App\Models\GradeRoster::StateLateNotSubmitted => 'Submission overdue',
                                    default => 'Draft',
                                } : 'Roster needs update';
                            @endphp
                            <li>
                                <a class="faculty-class-card tala-task-surface" {{ \Filament\Support\generate_href_html($this::getUrl(['roster' => $classRoster->id])) }}>
                                    <div class="faculty-class-card-identity">
                                        <h3>{{ $course?->title }}</h3>
                                        <p>{{ collect([$course?->course?->code, $classRoster->section?->code])->filter()->implode(' · ') }}</p>
                                    </div>
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="faculty-class-card-arrow" />
                                    <div class="faculty-class-card-summary">
                                        <x-filament::badge color="gray">{{ $rosterStatus }}</x-filament::badge>
                                        @if ($classRow['current'])
                                            <span>{{ $classRow['count'] }} {{ str('student')->plural($classRow['count']) }}</span>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @empty
                <x-servitech-empty-state :illustrated="$classTerms->isEmpty()" :heading="$classTerms->isEmpty() ? 'No assigned classes yet' : ($classPaginator->total() > 0 ? 'No classes on this page' : 'No classes match your filters')" :description="$classTerms->isEmpty() ? 'Your classes will appear here after the Registrar sets up the term and teaching assignments. Expecting a class? Ask the Registrar to check your teaching assignment.' : ($classPaginator->total() > 0 ? 'The class list has changed. Return to the first page to continue.' : 'Try another course title, class code or term.')" icon="heroicon-o-magnifying-glass">
                    @if ($classTerms->isEmpty())
                        <x-filament::button tag="a" :href="$this::getUrl(['classes' => 1])" color="gray" icon="heroicon-m-arrow-path">Refresh classes</x-filament::button>
                    @elseif ($classPaginator->total() > 0)
                        <x-filament::button tag="a" :href="$classPaginator->url(1)" color="gray">Show first page</x-filament::button>
                    @else
                        <x-filament::button tag="a" :href="$this::getUrl(['classes' => 1])" color="gray">Clear filters</x-filament::button>
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
        <section class="faculty-membership-message" role="status"><h2>Class membership needs an update</h2><p>Ask the Registrar to refresh this class roster, then reload this page. The current learner list and outputs will be available after the records agree.</p></section>
    @else
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
                <div class="tala-record-browser-header">
                    <h2 id="faculty-students-heading">Students <span>{{ $workspace['total'] }}</span></h2>
                </div>
                <div id="faculty-student-browser" class="faculty-result-browse">
                <div class="faculty-result-search">
                    <label for="faculty-student-search" class="sr-only">Find a student</label>
                    <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                        <x-filament::input id="faculty-student-search" type="search" wire:model.live.debounce.300ms="studentSearch" placeholder="Name or student number" />
                    </x-filament::input.wrapper>
                    @if ($roster->state === \App\Models\GradeRoster::StateReturned)
                        <label class="faculty-result-filter">
                            <x-filament::input.checkbox wire:model.live="needsChangesOnly" />
                            <span>Only results needing changes ({{ $workspace['returned'] }})</span>
                        </label>
                    @endif
                    <p class="faculty-result-list-count" aria-live="polite">{{ $workspace['filtered'] }} of {{ $workspace['total'] }} students shown</p>
                </div>
                <ul class="tala-record-list faculty-result-student-list" aria-label="Students in this class" wire:loading.class="faculty-result-loading" wire:target="studentSearch,needsChangesOnly,goToStudentPage">
                    @forelse ($workspace['students'] as $student)
                        <li wire:key="roster-student-{{ $roster->id }}-{{ $student['id'] }}">
                            <x-record-option wire:click="selectStudent({{ $student['id'] }})" aria-controls="faculty-result-inspector" :selected="$student['id'] === $selectedStudentId" :result="$student['final_result']">
                                <strong>{{ $student['legal_name'] }}</strong><small>{{ $student['student_number'] }}</small>
                                @if ($student['returned'])<x-filament::badge color="gray" icon="heroicon-m-arrow-uturn-left">Changes requested</x-filament::badge>@endif
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
                    @php($incContext = $this->selectedIncContext())
                    @if ($selected['returned'])
                        <div class="tala-review-feedback faculty-result-feedback">
                            <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="size-5" />
                            <div><h3>Changes requested by Registrar</h3><p>{{ $selected['return_reason'] }}</p></div>
                        </div>
                    @endif
                    @if ($roster->state === \App\Models\GradeRoster::StateReleased)
                        <x-record-result :result="$selected['final_result']" :completion="$incContext" :description="$incContext ? 'Incomplete grade' : 'Official result'" />
                        @if ($incContext)<x-inc-follow-up :context="$incContext" :show-deadline="false" />@endif
                    @else
                        <div class="faculty-result-fields" wire:key="student-result-{{ $selected['id'] }}">
                            {{ $this->form }}
                        </div>
                    @endif
                    @if (!$incContext)
                        @if (!$designated)
                            <div class="faculty-result-entry-state"><x-filament::icon icon="heroicon-o-lock-closed" class="size-5" /><p>View-only co-faculty. The designated faculty records results.</p></div>
                        @elseif ($this->canEditSelectedRoster() && !in_array($selected['id'], $editableRowIds, true))
                            <div class="faculty-result-entry-state"><x-filament::icon icon="heroicon-o-lock-closed" class="size-5" /><p>This result is unchanged. Only results returned by the Registrar can be edited.</p></div>
                        @endif
                        @if ($roster->state === \App\Models\GradeRoster::StateReleased)
                            <x-workflow-handoff current-owner="Official result released" current-task="Registrar records a correction only when the institution has authorized a change. Earlier results stay in history." />
                        @elseif ($roster->state === \App\Models\GradeRoster::StateSubmitted)
                            <x-workflow-handoff current-owner="Registrar" current-area="Class grades · Review" current-task="Review the submitted roster and release it, or return specified results for changes." next-owner="Student" next-area="Academics · Released grades" next-task="See official results after Registrar release." />
                        @elseif (!$entryWindow['is_open'])
                            <x-workflow-handoff current-owner="Registrar" current-area="Class grades · Entry access" :current-task="$entryWindow['basis'].'. Review the Grade Entry calendar or a scoped late-entry authorization.'" />
                        @else
                            <x-workflow-handoff current-owner="Designated Faculty" current-area="Grade rosters · Results" :current-task="$roster->state === \App\Models\GradeRoster::StateReturned ? 'Update the results returned by Registrar, then resubmit the complete roster.' : 'Record each student’s final result. Changes save as a private draft; submit the complete roster when ready.'" next-owner="Registrar" next-area="Class grades · Review" next-task="Review the complete roster after submission." />
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
            <x-slot:toolbar>
                <x-supporting-details summary="Class details" inset class="faculty-result-context">
                    <div class="faculty-roster-details">
                        <section class="faculty-grade-window" aria-label="Grade entry dates">
                            <div class="faculty-grade-window-heading">
                                <h3>Grade entry</h3>
                                <x-filament::badge color="gray">{{ $entryWindow['is_open'] ? 'Open' : 'Not open' }}</x-filament::badge>
                            </div>
                            @if ($entryWindow['basis'] !== 'Grade Entry window')<p>{{ $entryWindow['basis'] }}</p>@endif
                            @if ($entryWindow['opens_at'] && $entryWindow['closes_at'])
                                <dl class="faculty-grade-window-dates">
                                    <div><dt>Opens</dt><dd><strong>{{ $entryWindow['opens_at']->copy()->timezone('Asia/Manila')->format('M j, Y') }}</strong><span>{{ $entryWindow['opens_at']->copy()->timezone('Asia/Manila')->format('g:i A') }}</span></dd></div>
                                    <div><dt>Closes</dt><dd><strong>{{ $entryWindow['closes_at']->copy()->timezone('Asia/Manila')->format('M j, Y') }}</strong><span>{{ $entryWindow['closes_at']->copy()->timezone('Asia/Manila')->format('g:i A') }}</span></dd></div>
                                </dl>
                                <p class="faculty-grade-window-timezone">Asia/Manila</p>
                            @endif
                        </section>
                        <section class="faculty-roster-references" aria-label="Class record references">
                            <h3>Class record</h3>
                            <dl>
                                <div><dt>Designated faculty</dt><dd>{{ $roster->teachingAssignment?->faculty?->name ?? $roster->faculty?->name ?? 'Not recorded' }}</dd></div>
                                <div><dt>Submitted roster</dt><dd>{{ $roster->current_version_number > 0 ? 'Version '.$roster->current_version_number : 'Not submitted yet' }}</dd></div>
                                @if ($entryWindow['reference'])<div class="faculty-roster-reference"><dt>Calendar or entry approval</dt><dd>{{ $entryWindow['reference'] }}</dd></div>@endif
                                @if (filled($roster->teachingAssignment?->authority_reference))<div class="faculty-roster-reference"><dt>Assignment approval</dt><dd>{{ $roster->teachingAssignment->authority_reference }}</dd></div>@endif
                            </dl>
                        </section>
                    </div>
                </x-supporting-details>
            </x-slot:toolbar>
            <x-slot:footer class="faculty-result-footer">
                <div class="faculty-result-progress-copy">
                    <strong>{{ $workspace['recorded'] }} of {{ $workspace['total'] }} results recorded</strong>
                    @if ($workspace['returned'] > 0 || !$designated)<p>{{ $workspace['returned'] > 0 ? trans_choice('{1} :count result returned for changes|[2,*] :count results returned for changes', $workspace['returned']) : '' }}{{ !$designated ? ' · View-only co-faculty' : '' }}</p>@endif
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
                        {{ $this->submitAction }}
                    </div>
                    <p class="faculty-result-submission-scope">Submission includes all {{ $workspace['total'] }} students, including those outside this view.</p>
                @endif
            </x-slot:footer>
            </x-record-workspace>
        </form>
    @endif
</x-filament-panels::page>
