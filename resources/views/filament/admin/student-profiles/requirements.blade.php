@php($requirements = $this->requirementsProfile)
<div class="tala-profile-view-intro"><h2>Requirements & follow-up</h2><p class="tala-profile-muted">Documents to review and existing restrictions, with the responsible office and permitted next action.</p></div>
@if (! $requirements['hasChecklist'])
    <section class="tala-profile-ledger">
        <x-filament::empty-state heading="No document checklist recorded" description="No document requirements have been recorded for this student. This does not confirm document clearance." icon="heroicon-o-clipboard-document-check" compact :contained="false" />
    </section>
@endif
{{ $this->requirements }}
@if (! $requirements['hasHolds'])
    <p class="tala-profile-source-note"><x-filament::icon icon="heroicon-o-shield-check" />No restrictions recorded. Document requirements are evaluated separately.</p>
@endif
