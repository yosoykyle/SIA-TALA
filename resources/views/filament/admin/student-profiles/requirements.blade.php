@php($requirements = $this->requirementsProfile)
<div class="tala-profile-view-intro"><h2>Requirements & follow-up</h2><p class="tala-profile-muted">Documents to review and existing restrictions, with the responsible office and permitted next action.</p></div>
@php($papers = $this->admissionPapers)
@if ($papers['items'] !== [])
    <section class="tala-profile-ledger" aria-labelledby="profile-admission-papers-heading">
        <header class="tala-profile-ledger__toolbar">
            <div>
                <h2 id="profile-admission-papers-heading">Admission papers</h2>
                <p class="tala-profile-muted">Papers from the admission application. A paper the Registrar has not ticked yet is To be followed; enrollment does not wait for it.</p>
            </div>
            @if ($this->updateAdmissionPapersAction->isVisible()){{ $this->updateAdmissionPapersAction }}@endif
        </header>
        <div class="tala-profile-ledger__support">
            <ul class="tala-process-documents" aria-label="Admission papers">
                @foreach ($papers['items'] as $paper)
                    <li><span>{{ $paper['label'] }} <span class="tala-profile-muted">· {{ $paper['due'] }}</span></span><x-filament::badge :color="$paper['passed'] ? 'success' : 'warning'" size="sm">{{ $paper['status'] }}</x-filament::badge></li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
@if (! $requirements['hasChecklist'] && $papers['items'] === [])
    <section class="tala-profile-ledger">
        <x-filament::empty-state heading="No document checklist recorded" description="No document requirements have been recorded for this student. This does not confirm document clearance." icon="heroicon-o-clipboard-document-check" compact :contained="false" />
    </section>
@endif
{{ $this->requirements }}
@if (! $requirements['hasHolds'])
    <p class="tala-profile-source-note"><x-filament::icon icon="heroicon-o-shield-check" />No restrictions recorded. Document requirements are evaluated separately.</p>
@endif
