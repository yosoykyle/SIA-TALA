{{-- Admission cycle overview · Applicant guidance and source details (#110): same fact strip as Intake. --}}
<div class="tala-cycle-overview">
    <dl class="tala-calendar-facts tala-cycle-overview__facts--four">
        <div class="tala-calendar-facts__item">
            <dt>Internal cycle code</dt>
            <dd>{{ $code }}</dd>
            <dd class="tala-calendar-facts__note">{{ $codeNote }}</dd>
        </div>
        <div class="tala-calendar-facts__item">
            <dt>Admissions support contact</dt>
            <dd @class(['tala-cycle-overview__missing' => blank($supportContact)])>{{ $supportContact ?: 'Not set' }}</dd>
        </div>
        <div class="tala-calendar-facts__item">
            <dt>Privacy notice reference</dt>
            <dd @class(['tala-cycle-overview__missing' => blank($privacyReference)])>{{ $privacyReference ?: 'Not set' }}</dd>
        </div>
        <div class="tala-calendar-facts__item">
            <dt>Publication checks</dt>
            <dd>
                <x-filament::badge :color="$checksPassed ? 'success' : 'warning'" size="sm">{{ $checksPassed ? 'All checks passed' : $checksLabel }}</x-filament::badge>
            </dd>
        </div>
    </dl>

    <section class="tala-cycle-overview__group" aria-labelledby="cycle-instructions-heading">
        <h3 id="cycle-instructions-heading" class="tala-cycle-overview__group-heading">Instructions for applicants</h3>
        @if (filled($instructions))
            <p class="tala-cycle-overview__prose">{{ $instructions }}</p>
        @else
            <p class="tala-cycle-overview__missing">No instructions recorded.</p>
        @endif
    </section>
</div>
