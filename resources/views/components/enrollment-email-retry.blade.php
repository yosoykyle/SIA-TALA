@props(['action'])
@if ($action->isVisible())
    <section class="student-registration-help-panel" aria-label="Enrollment email delivery">
        <h2>An enrollment email was not sent</h2>
        <p>Send it again to your account email. This only retries delivery; it does not change your enrollment.</p>
        <div class="student-finance-actions">{{ $action }}</div>
    </section>
@endif
