@php
    $state = $state ?? 'closed';
    $cycle = $cycle ?? null;
    $isUpcoming = $state === 'upcoming' && is_array($cycle);
@endphp

<div class="tala-auth-verify-card tala-auth-closed-card" data-tala-registration-closed data-admissions-state="{{ $state }}">
    <div class="tala-auth-verify-card__body">
        @if ($isUpcoming)
            <div class="tala-auth-closed-schedule">
                <p class="tala-auth-closed-schedule__heading">
                    {{ $cycle['label'] ?? 'Upcoming admission cycle' }}@if (filled($cycle['term'] ?? null)) · {{ $cycle['term'] }}@endif
                </p>
                <dl class="tala-auth-closed-schedule__facts">
                    @if (filled($cycle['opens_at'] ?? null))
                        <div>
                            <dt>Applications open</dt>
                            <dd>{{ $cycle['opens_at'] }} (Asia/Manila time)</dd>
                        </div>
                    @endif
                    @if (filled($cycle['closes_at'] ?? null))
                        <div>
                            <dt>Applications close</dt>
                            <dd>{{ $cycle['closes_at'] }} (Asia/Manila time)</dd>
                        </div>
                    @endif
                </dl>
            </div>
        @endif

        <p class="tala-auth-verify-card__lead">
            @if ($isUpcoming)
                New applicant accounts will open on the scheduled date above.
            @else
                New applicant accounts can only be created while an admission cycle is open.
            @endif
        </p>

        <p class="tala-auth-verify-card__note">
            Existing applicants, students, and staff can still sign in to access their accounts.
        </p>
    </div>

    <div class="tala-auth-verify-card__actions tala-workspace-feature__action">
        <x-filament::button
            tag="a"
            :href="route('filament.applicant.auth.login')"
            color="primary"
            icon="heroicon-o-arrow-left"
            size="md"
            class="w-full justify-center"
        >
            Back to sign in
        </x-filament::button>
    </div>
</div>
