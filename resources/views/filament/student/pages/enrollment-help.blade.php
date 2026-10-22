<section class="student-registration-help-panel" aria-label="Enrollment help">
    <div>
        <h2>{{ $terminal ? 'Need help with this enrollment?' : 'Need to change your enrollment?' }}</h2>
        @if (! $terminal)<p>{{ $confirmed ? 'Contact the Registrar about changes to your confirmed subjects.' : 'Contact the Registrar to change your proposed subjects.' }}</p>@endif
        @if ($this->cancelRegistrationAction->isVisible())<p>If you won't continue this term, you can cancel this enrollment.</p>@endif
    </div>
    <div class="student-finance-actions">
        @if ($this->cancelRegistrationAction->isVisible()){{ $this->cancelRegistrationAction }}@endif
    </div>
</section>

<x-enrollment-email-retry :action="$this->resendRegistrationEmailAction" />
