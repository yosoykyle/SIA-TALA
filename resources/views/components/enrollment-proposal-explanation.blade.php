@props([
    'enrollment',
    'proposal' => null,
    'audience' => \App\Actions\Enrollment\EnrollmentProposalExplanationProjection::AudienceStudent,
    'compact' => false,
])
@php
    $explanation = app(\App\Actions\Enrollment\EnrollmentProposalExplanationProjection::class)
        ->forEnrollment($enrollment, $proposal, $audience);
@endphp
<aside
    aria-label="{{ $explanation['heading'] }}"
    style="margin-block: .875rem; padding: {{ $compact ? '.875rem 1rem' : '1rem 1.25rem' }}; border: 1px solid var(--tala-edge, rgba(15, 23, 42, .12)); border-radius: .75rem; background: var(--tala-surface, #fff); display: grid; gap: .625rem;"
>
    <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem;">
        <h3 style="margin: 0; font-size: .9375rem; font-weight: 600;">{{ $explanation['heading'] }}</h3>
        <x-filament::badge :color="$explanation['selection_basis'] === \App\Models\Enrollment::SelectionIndividuallyAdvised ? 'warning' : 'info'" size="sm">
            {{ $explanation['basis_label'] }}
        </x-filament::badge>
    </div>
    <p style="margin: 0; font-size: .875rem; line-height: 1.5; color: var(--tala-muted, #475569);">
        {{ $explanation['summary'] }}
    </p>
    @if ($explanation['curriculum_label'] || $explanation['unit_load_label'] || $explanation['transfer_credit_summary'] || $explanation['academic_note'])
        <dl style="margin: 0; display: grid; gap: .375rem; font-size: .8125rem; line-height: 1.5;">
            @if ($explanation['curriculum_label'])
                <div style="display: flex; flex-wrap: wrap; gap: .25rem .5rem;">
                    <dt style="font-weight: 600;">Curriculum:</dt>
                    <dd style="margin: 0; color: var(--tala-muted, #475569);">
                        {{ $explanation['curriculum_label'] }}@if ($explanation['curriculum_note']) · {{ $explanation['curriculum_note'] }}@endif
                    </dd>
                </div>
            @endif
            @if ($explanation['unit_load_label'])
                <div style="display: flex; flex-wrap: wrap; gap: .25rem .5rem;">
                    <dt style="font-weight: 600;">Curriculum position &amp; load:</dt>
                    <dd style="margin: 0; color: var(--tala-muted, #475569);">
                        {{ $explanation['unit_load_label'] }}@if ($explanation['overload_note']) · {{ $explanation['overload_note'] }}@endif
                    </dd>
                </div>
            @endif
            @if ($explanation['transfer_credit_summary'])
                <div style="display: flex; flex-wrap: wrap; gap: .25rem .5rem;">
                    <dt style="font-weight: 600;">Transfer credit evaluation:</dt>
                    <dd style="margin: 0; color: var(--tala-muted, #475569);">
                        {{ $explanation['transfer_credit_summary'] }}@if ($explanation['transfer_credit_authority']) · Ref: {{ $explanation['transfer_credit_authority'] }}@endif
                    </dd>
                </div>
            @endif
            @if ($explanation['academic_note'])
                <div style="display: flex; flex-wrap: wrap; gap: .25rem .5rem;">
                    <dt style="font-weight: 600;">Academic basis:</dt>
                    <dd style="margin: 0; color: var(--tala-muted, #475569);">
                        {{ $explanation['academic_note'] }}@if ($audience === \App\Actions\Enrollment\EnrollmentProposalExplanationProjection::AudienceRegistrar && $explanation['academic_source']) ({{ $explanation['academic_source'] }})@endif
                    </dd>
                </div>
            @endif
        </dl>
    @endif
    @if ($explanation['evaluation_url'] && $explanation['evaluation_label'])
        <div>
            <x-filament::link :href="$explanation['evaluation_url']" :icon="$audience === \App\Actions\Enrollment\EnrollmentProposalExplanationProjection::AudienceRegistrar ? 'heroicon-o-academic-cap' : 'heroicon-o-map'">
                {{ $explanation['evaluation_label'] }}
            </x-filament::link>
        </div>
    @endif
</aside>
