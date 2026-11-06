<x-filament-panels::page :wire:init="$torConfirmation ? 'confirmReviewedTor' : null">
    @if ($completionView === 'graduation')
        @php($students = $this->getTableRecords())
        @if ($students->isEmpty())
            {{ $this->table }}
        @else
        <x-record-workspace collection-label="students" selection-parameter="student" selection-actions="selectGraduationStudent" class="tala-graduation-workspace">
            <x-slot:browser class="tala-record-browser--table" aria-label="Graduation students">{{ $this->table }}</x-slot:browser>
            <x-slot:collectionFooter><x-record-collection-footer :paginator="$this->getTableRecords()" method="gotoGraduationPage" label="students" :page-options="[10,25,50]" /></x-slot:collectionFooter>
            <x-slot:inspector aria-label="Graduation review">
                @if ($this->selectedGraduationStudent())
                    @include('filament.pages.partials.graduation-review', ['detail' => $this->graduationDetail()])
                @else
                    <x-servitech-empty-state heading="Choose a student" description="Find a student to review graduation requirements and recorded results." icon="heroicon-o-academic-cap" />
                @endif
            </x-slot:inspector>
        </x-record-workspace>
        @endif
    @else
        @php($requests = $this->torRequests)
        @php($selected = $this->selectedTorRequest())
        @if ($requests->isEmpty())
            @php($filtered = filled($torSearch) || filled($torScope))
            <section class="servitech-empty-collection" aria-label="Transcript requests" wire:key="tor-empty-desk">
                @include('filament.pages.partials.tor-request-browser-header', ['showFilters' => $filtered])
                <x-servitech-empty-state :illustrated="! $filtered" :heading="$filtered ? 'No matching requests' : 'No TOR requests yet'" :description="$filtered ? 'Clear the search or change the request status.' : 'Record a request when the school receives it. Received and due dates will appear here.'" icon="heroicon-o-magnifying-glass">
                    @if ($filtered)<x-filament::button color="gray" wire:click="clearTorFilters" icon="heroicon-m-arrow-path">Clear search and filters</x-filament::button>@endif
                </x-servitech-empty-state>
            </section>
        @else
        <x-record-workspace wire:key="tor-request-desk" collection-label="TOR requests" selection-parameter="request" selection-actions="selectTorRequest,gotoTorPage" loading-label="Updating selected TOR request" class="tala-tor-workspace">
            <x-slot:browser aria-label="Transcript requests">
                @include('filament.pages.partials.tor-request-browser-header')
                <div class="tala-tor-queue-head" aria-hidden="true"><span>Student / program</span><span>Request</span><span>Due</span><span>Status</span></div>
                <ul class="tala-record-list">
                    @foreach ($requests as $item)
                        <li wire:key="tor-request-{{ $item->id }}"><x-record-option class="tala-tor-queue-row" :selected="$selected?->id === $item->id" :wire:click="'selectTorRequest('.$item->id.')'">
                            <x-slot:meta></x-slot:meta>
                            <span><strong>{{ $item->studentProfile->last_name }}, {{ $item->studentProfile->first_name }}</strong><small>{{ $item->studentProfile->student_number }}</small><small>{{ $item->studentProfile->program?->name }}</small></span>
                            <span><strong>{{ $item->external_request_reference }}</strong><small>Received {{ $item->requested_on->format('M j, Y') }}</small></span>
                            <span><small class="tala-tor-narrow-label">Due</small>{{ $item->due_on->format('M j, Y') }}</span>
                            <span class="tala-tor-queue-status">{{ $this->torRequestStatus($item) }}</span>
                        </x-record-option></li>
                    @endforeach
                </ul>
            </x-slot:browser>
            <x-slot:collectionFooter><x-record-collection-footer :paginator="$requests" method="gotoTorPage" label="requests" /></x-slot:collectionFooter>
            <x-slot:inspector aria-label="Selected TOR request">
                @if ($selected)
                    @php($detail = $this->torDetail())
                    @php($student = $selected->studentProfile)
                    @php($signatory = $this->torSignatory)
                    <div wire:key="tor-detail-{{ $selected->id }}" class="tala-tor-request-content">
                        <x-record-identity :name="$student->last_name.', '.$student->first_name" :number="$student->student_number" :program="$student->program?->name" :photo-url="$student->user?->getFilamentAvatarUrl()" :initials="mb_substr($student->first_name,0,1).mb_substr($student->last_name,0,1)" />
                        <dl class="tala-tor-request-facts"><div><dt>Request</dt><dd>{{ $selected->external_request_reference }}</dd></div><div><dt>Received</dt><dd>{{ $selected->requested_on->format('M j, Y') }}</dd></div><div><dt>Due</dt><dd>{{ $selected->due_on->format('M j, Y') }}</dd></div></dl>
                        @if (! $detail['current'] && blank($signatory['name']) && blank($selected->signatory_name))
                            <div class="tala-tor-setup"><x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" /><div><h3>Set the name printed on the TOR</h3><p>Save the school-confirmed name and title once for future TORs.</p></div>{{ $this->getAction('setTorSignatory') }}</div>
                        @elseif (! $detail['current'] && $detail['blocker'])
                            <div class="tala-tor-setup"><x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" /><div><h3>Resolve this before document review</h3><p>{{ $detail['blocker'] }}</p></div>@if (\App\Filament\Resources\StudentProfiles\StudentProfileResource::canView($student))<x-filament::button tag="a" :href="\App\Filament\Resources\StudentProfiles\StudentProfileResource::getUrl('view',['record'=>$student])" color="gray" icon="heroicon-o-identification">Open student record</x-filament::button>@endif</div>
                        @endif
                        <section class="tala-tor-readiness" aria-label="Issuance status">
                            <div><x-filament::icon icon="heroicon-o-document-check" aria-hidden="true" /><div><h3>{{ $detail['current'] ? 'Official TOR issued' : ($detail['blocker'] ? 'Document review pending' : 'Released academic record available') }}</h3><p>{{ $detail['current'] ? 'This version is retained unchanged.' : 'Only official released results appear, including any released INC.' }}</p></div></div>
                            <div><x-filament::icon :icon="$detail['restrictions']->isEmpty() ? 'heroicon-o-check-circle' : 'heroicon-o-lock-closed'" aria-hidden="true" /><div><h3>Accounting</h3><p>{{ $detail['restrictions']->isEmpty() ? 'No active Accounting restriction' : $detail['restrictions']->pluck('reason')->implode('; ') }}</p></div></div>
                        </section>
                        @if ($detail['current'])
                            <x-workflow-handoff current-owner="Registrar" current-area="Official TOR issued" current-task="Open the retained document. The school handles physical signing, sealing and delivery." />
                            <div class="tala-record-actions">{{ $this->getAction('viewIssued') }}{{ $this->getAction('voidLatest') }}</div>
                        @elseif ($detail['restrictions']->isNotEmpty())
                            <x-workflow-handoff current-owner="Accounting" current-area="Resolve the TOR restriction" current-task="Review the named restriction in the student account." next-owner="Registrar" next-area="Review TOR" next-task="Review the transcript after the restriction is resolved." />
                        @else
                            <x-workflow-handoff current-owner="Registrar" :current-area="$detail['blocker'] ? (blank($signatory['name']) && blank($selected->signatory_name) ? 'Set TOR signatory' : 'Resolve the missing source') : 'Review TOR'" :current-task="$detail['blocker'] ? (blank($signatory['name']) && blank($selected->signatory_name) ? 'Save the school-confirmed name and title printed on the document.' : 'Use the guidance above. The request’s received and due dates stay unchanged.') : 'Check the academic record and printed certification.'" next-owner="Registrar" :next-area="$detail['blocker'] ? 'Review TOR' : 'Confirm issuance'" :next-task="$detail['blocker'] ? 'Open and check the exact document once its sources are ready.' : 'Confirm only after reviewing the exact document.'" />
                            <div class="tala-record-actions">{{ $this->getAction('reviewTor') }}@if ($detail['replacement']){{ $this->getAction('replaceLatest') }}@elseif (! $this->getAction('issue')->isDisabled()){{ $this->getAction('issue') }}@endif</div>
                        @endif
                        <x-record-history heading="Request history" :paginator="$this->torHistory" method="gotoTorHistoryPage">
                            @foreach ($this->torHistory as $entry)
                                @php($event = $entry['event'])
                                <x-record-history-item :heading="$event ? (string) str($event->type)->headline() : 'Request recorded by Registrar'" :date="$event?->recorded_at ?? $selected->recorded_at" :icon="$event ? 'heroicon-o-document-check' : 'heroicon-o-inbox-arrow-down'">
                                    @if ($event)
                                        @if ($event->recorder)<p>{{ $event->recorder->name }}</p>@endif
                                        @if ($event->reason)<p>{{ $event->reason }}</p>@endif
                                        @if ($event->transcript_snapshot_id)<x-filament::button tag="a" :href="route('transcript-snapshots.show',$event->transcript_snapshot_id)" color="gray" size="sm" icon="heroicon-o-document-text">View {{ $event->reference ?: 'document' }}</x-filament::button>@endif
                                    @else
                                        <p>Received {{ $selected->requested_on->format('M j, Y') }} · Due {{ $selected->due_on->format('M j, Y') }}</p>
                                    @endif
                                </x-record-history-item>
                            @endforeach
                        </x-record-history>
                        @if (! blank($signatory['name']))<p class="tala-tor-context-note">Printed signatory: {{ $signatory['name'] }} · {{ $signatory['title'] }}. {{ $this->getAction('setTorSignatory')->label('Change TOR signatory')->link() }}</p>@endif
                    </div>
                @else
                    <x-servitech-empty-state heading="Choose a request" description="Select a request to review the transcript and its history." />
                @endif
            </x-slot:inspector>
        </x-record-workspace>
        @endif
        <x-filament-actions::modals />
    @endif
</x-filament-panels::page>
