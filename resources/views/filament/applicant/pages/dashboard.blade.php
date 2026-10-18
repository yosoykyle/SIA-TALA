<x-filament-panels::page>
    @include('filament-actions::components.modals')
    @php($application = $this->currentApplication())
    @php($stages = $this->journeyStages($application))
    @php($stepNumber = $this->journeyStepNumber($stages))
    @php($statusWords = ['completed' => 'Completed', 'current' => 'In progress', 'pending' => 'Pending', 'unavailable' => 'Not available'])
    @if ($application)
        @php($projection = $this->readinessProjection($application))
        @php($activeCorrection = $application->correctionRequests->where('state', \App\Models\ApplicationCorrectionRequest::StateActive)->sortByDesc('sequence')->first())
        @php($currentDecision = $application->decisions->firstWhere('id', $projection['decision_id']))
    @endif

        @php($deadline = $this->applicationDeadline($application))
        @php($checklist = $this->requirementChecklist($application))
        @php($announcements = $this->schoolAnnouncements())
        @php($support = $this->supportContact($application))
        <div class="space-y-6">
            <div class="tala-home">
                <div class="tala-home__summary">
                    <x-filament::section class="tala-home-summary" icon="heroicon-o-document-text" icon-color="primary">
                        <x-slot name="heading">Application status</x-slot>
                        @if ($application)
                            <div class="tala-home-status">
                                <x-filament::badge :color="$this->statusColor($application->application_state)">{{ $this->statusLabel($application->application_state) }}</x-filament::badge>
                                <p>{{ $this->statusExplanation($application) }}</p>
                            </div>
                            <dl class="tala-home-facts">
                                <div><dt>Application number</dt><dd><x-application-reference :value="$application->application_reference" /></dd></div>
                                <div><dt>Program</dt><dd>{{ $application->program ? \App\Models\Program::applicantName($application->program->name) : 'Program not selected' }}</dd></div>
                                <div><dt>Student type</dt><dd>{{ \App\Models\AdmissionCycle::studentTypeLabel($application->application_path) }}</dd></div>
                            </dl>
                        @else
                            <div class="tala-home-status">
                                <x-filament::badge color="gray">Not started</x-filament::badge>
                                <p>You haven't started an application yet.</p>
                            </div>
                        @endif
                    </x-filament::section>

                    <x-filament::section class="tala-home-summary" icon="heroicon-o-calendar-days" icon-color="primary">
                        <x-slot name="heading">Application deadline</x-slot>
                        <div class="tala-home-deadline">
                            <p class="tala-home-deadline__label">{{ $deadline['label'] }}</p>
                            <p class="tala-home-deadline__value">{{ $deadline['value'] ?? 'No deadline' }}</p>
                            <p class="tala-home-deadline__note">{{ $deadline['note'] }}</p>
                        </div>
                    </x-filament::section>
                </div>

                <x-filament::section class="tala-journey-card tala-home__journey">
                    <x-slot name="heading">Your application journey</x-slot>

                    <ol class="tala-journey" aria-label="Application journey">
                        @foreach ($stages as $index => $stage)
                            <li class="tala-journey__stage tala-journey__stage--{{ $stage['status'] }}" data-journey-stage="{{ $stage['status'] }}" @if ($stage['status'] === 'current') aria-current="step" @endif>
                                <span class="tala-journey__marker" aria-hidden="true">
                                    @if ($stage['status'] === 'completed')
                                        <x-filament::icon icon="heroicon-m-check" class="size-4" />
                                    @else
                                        {{ $index + 1 }}
                                    @endif
                                </span>
                                <span class="tala-journey__text">
                                    <span class="tala-journey__label">{{ $stage['label'] }}</span>
                                    @php($statusText = $stage['statusText'] ?? $statusWords[$stage['status']])
                                    <span class="tala-journey__status">{{ $statusText }}<span class="fi-sr-only">.</span></span>
                                    @if ($stage['detail'] !== $statusText && $stage['detail'] !== 'Pending')
                                        <span class="tala-journey__detail">{{ $stage['detail'] }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>

                    <div class="tala-journey-next">
                        <div class="tala-journey-next__copy">
                            <p class="tala-journey-next__step">Step {{ $stepNumber }} of {{ count($stages) }} · {{ $stages[$stepNumber - 1]['label'] }}</p>
                            @if (! $application)
                                <p class="tala-application-next-action">
                                    {{ $this->admissionsAreOpen() ? 'Start an application for an open admission cycle.' : 'Applications are currently closed. Check the school home page for the next admission cycle.' }}
                                </p>
                            @else
                                <p class="tala-application-next-action">{{ $this->nextAction($application) }}</p>
                                @if ($activeCorrection)
                                    <p><strong>{{ $activeCorrection->isOverdue() ? 'Correction overdue:' : 'Correction due:' }}</strong> {{ $activeCorrection->due_at->timezone(config('app.display_timezone'))->format('M j, Y, g:i A') }} (Asia/Manila)<br>{{ $activeCorrection->applicant_instruction }}</p>
                                @elseif (($projection['clearance_result'] ?? null) === 'ActionNeeded')
                                    <p>{{ collect($projection['blockers'])->firstWhere('source', 'Registrar enrollment clearance')['recovery'] ?? '' }}</p>
                                @endif
                                @if ($currentDecision?->applicant_explanation)
                                    <p><strong>Admission decision remarks:</strong> {{ $currentDecision->applicant_explanation }}</p>
                                @endif
                            @endif
                        </div>
                        <div class="tala-journey-next__action">
                            @if (! $application)
                                @if ($this->admissionsAreOpen())
                                    <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl()" tag="a" icon="heroicon-m-arrow-right" icon-position="after">
                                        Start application
                                    </x-filament::button>
                                @endif
                            @elseif ($application->application_state === \App\Models\AdmissionApplication::StateDraft && ! app(\App\Queries\Admissions\ReadyApplicantProjectionQuery::class)->draftCycleIsOpen($application))
                                {{-- Closed cycle: no primary action (F71); a quiet link keeps the read-only draft reachable (#57). --}}
                                <a class="tala-home-link" href="{{ \App\Filament\Applicant\Pages\Application::getUrl(['application' => $application->id]) }}">Inspect saved draft</a>
                            @elseif (in_array($application->application_state, [\App\Models\AdmissionApplication::StateDraft, \App\Models\AdmissionApplication::StateActionNeeded], true))
                                <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application->id])" tag="a" icon="heroicon-m-arrow-right" icon-position="after">
                                    {{ $application->application_state === \App\Models\AdmissionApplication::StateDraft ? 'Continue application' : 'Respond to correction' }}
                                </x-filament::button>
                            @elseif ($application->allowsSubmittedEdit())
                                <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application->id])" tag="a" icon="heroicon-m-arrow-right" icon-position="after">
                                    Update application
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                </x-filament::section>

                <div class="tala-home__rail">
                    <x-filament::section class="tala-home-checklist" icon="heroicon-o-clipboard-document-list" icon-color="primary">
                        <x-slot name="heading">Requirements checklist</x-slot>
                        @if (! $application)
                            <p class="tala-home-muted">Your required documents appear here once you start an application.</p>
                        @elseif ($checklist['items'] === [])
                            <p class="tala-home-muted">No document uploads are required for this application.</p>
                        @else
                            <ul class="tala-checklist" aria-label="Required documents">
                                @foreach ($checklist['items'] as $item)
                                    <li class="tala-checklist__item">
                                        <span class="tala-checklist__mark tala-checklist__mark--{{ $item['color'] }}" aria-hidden="true">
                                            @if (in_array($item['color'], ['success', 'info'], true))
                                                <x-filament::icon icon="heroicon-m-check" class="size-3.5" />
                                            @elseif ($item['color'] === 'warning')
                                                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="size-3" />
                                            @endif
                                        </span>
                                        <span class="tala-checklist__label">{{ $item['label'] }}</span>
                                        <x-filament::badge size="sm" :color="$item['color']">{{ $item['state'] }}</x-filament::badge>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="tala-checklist__summary">{{ count($checklist['items']) }} {{ str('document')->plural(count($checklist['items'])) }} required · <a class="tala-home-link" href="{{ $checklist['url'] }}">{{ $checklist['linkLabel'] }}</a></p>
                        @endif
                    </x-filament::section>

                    <x-filament::section class="tala-home-dates" icon="heroicon-o-megaphone" icon-color="primary">
                        <x-slot name="heading">Important dates</x-slot>
                        @if ($application)
                            <dl class="tala-home-facts">
                                @foreach ($this->importantDates($application) as $date)
                                    <div><dt>{{ $date['label'] }}</dt><dd>{{ $date['value'] }}</dd></div>
                                @endforeach
                            </dl>
                        @endif
                        <div class="tala-announcements">
                            <h3 class="tala-announcements__heading">Announcements</h3>
                            @forelse ($announcements as $notice)
                                <article class="tala-announcement">
                                    <h4 class="tala-announcement__title">{{ $notice->title }}</h4>
                                    @if ($notice->published_at)
                                        <p class="tala-announcement__date">Published {{ $notice->published_at->timezone(config('app.display_timezone'))->format('M j, Y') }}</p>
                                    @endif
                                    <p class="tala-announcement__message">{{ str($notice->message)->limit(180) }}</p>
                                    @if ($notice->link_url && $notice->link_label)
                                        <a class="tala-home-link" href="{{ $notice->link_url }}">{{ $notice->link_label }}</a>
                                    @endif
                                </article>
                            @empty
                                <p class="tala-home-muted">No announcements yet.</p>
                            @endforelse
                        </div>
                    </x-filament::section>
                </div>

                @if ($support)
                    <section class="tala-home-help" aria-labelledby="tala-home-help-title">
                        <span class="tala-home-help__icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-lifebuoy" class="size-6" /></span>
                        <div class="tala-home-help__copy">
                            <h2 id="tala-home-help-title">Need help?</h2>
                            <p>Questions about your application? Contact admissions support.</p>
                            <p class="tala-home-help__contact"><a class="tala-home-link" href="{{ $support['href'] }}">{{ $support['text'] }}</a></p>
                        </div>
                        <x-filament::button :href="route('home', ['modal' => 'support'])" tag="a" color="gray" icon="heroicon-m-chat-bubble-left-right">Contact support</x-filament::button>
                    </section>
                @endif
            </div>

            @php($registrationCase = $this->registrationCase())
            @php($registrationReadiness = $this->registrationReadiness())
            @if ($registrationCase && $registrationReadiness)
                <x-filament::section
                    heading="Enrollment checkpoints"
                    :description="($registrationCase->term?->label ?? 'Exact Term').' · '.$registrationCase->case_reference"
                    icon="heroicon-o-clipboard-document-check"
                >
                    @php($proposal = $registrationCase->currentProposalVersion)
                    @php($checkpointRows = [
                        ['Student eligibility', $registrationReadiness['eligibility'] && $registrationReadiness['identity'], 'Admissions and confirmed identity/contact source', 'Registrar', 'A stale or blocked source prevents the next enrollment action.', 'Contact the Registrar so the owning source can be corrected; no local override is created.'],
                        ['Confirmed proposed subjects', $registrationReadiness['confirmation'], 'Registration Proposal version '.($proposal?->version ?? 'not prepared'), 'Learner', 'Unconfirmed or superseded subjects cannot be placed or finalized.', 'Review and confirm the current issued proposal, or use attributable Registrar-assisted confirmation.'],
                        ['Valid class placement', $registrationReadiness['placement'], 'Published Timetable and reservations', 'Registrar', 'Only an affected course remains blocked; no waitlist or silent move is created.', 'The Registrar resolves the named prerequisite, class, capacity, conflict, or timetable-source blocker.'],
                        ['Accounting clearance', $registrationReadiness['finance'], 'Enrollment Payment Requirement', 'Accounting', 'Unavailable or unsatisfied current requirements block finalization only.', 'Accounting records the current assessment and valid payment or coverage result.'],
                        ['Registrar finalization', $registrationCase->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled, 'Atomic Registration Case result', 'Registrar', 'No Student activation, official roster, or COR exists until the transaction commits.', $registrationReadiness['ready'] ? 'All prior checkpoints are ready for Registrar finalization.' : 'Resolve the earlier named checkpoint, refresh current evidence, and retry.'],
                    ])
                    @php($currentCheckpoint = collect($checkpointRows)->search(fn ($row) => ! $row[1]))
                    <ol class="grid gap-3 lg:grid-cols-5">
                        @foreach ($checkpointRows as $index => [$label, $ready, $source, $owner, $consequence, $recovery])
                            <li class="rounded-xl bg-gray-50 p-4 dark:bg-white/5" @if ($currentCheckpoint === $index) aria-current="step" @endif>
                                <p class="font-semibold text-gray-950 dark:text-white">{{ $index + 1 }}. {{ $label }}</p>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $ready ? 'Verified' : 'Action required' }} · Owner: {{ $owner }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Source: {{ $source }} · As of {{ $registrationCase->updated_at?->timezone(config('app.display_timezone'))->format('M d, Y g:i A') }}</p>
                                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $consequence }} {{ $recovery }}</p>
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                        Selection basis: {{ str($registrationCase->selection_basis)->headline() }}. The authoritative Application source determines this basis; you do not choose it.
                    </p>
                </x-filament::section>
            @endif

        </div>

</x-filament-panels::page>
