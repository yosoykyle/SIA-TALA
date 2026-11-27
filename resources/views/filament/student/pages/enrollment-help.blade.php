@if ($this->cancelRegistrationAction->isVisible())
    <div class="student-finance-actions">
        {{ $this->cancelRegistrationAction }}
    </div>
@endif

<x-enrollment-email-retry :action="$this->resendRegistrationEmailAction" />
