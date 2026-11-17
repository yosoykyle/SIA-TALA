@if (($studentChangeReviewStatus['pending'] ?? 0) > 0 || ($studentChangeReviewStatus['reviewed'] ?? 0) > 0)
    <section class="student-finance-task tala-task-surface" aria-labelledby="finance-student-changes-heading-{{ $studentChangeReviewId ?? 'current' }}">
        <h2 id="finance-student-changes-heading-{{ $studentChangeReviewId ?? 'current' }}">{{ $studentChangeReviewStatus['pending'] > 0 ? 'Accounting is reviewing a student change' : 'Accounting review recorded' }}</h2>
        <p>{{ $studentChangeReviewStatus['pending'] > 0 ? 'An applied change to your school record needs an account review. Contact Accounting for help.' : 'Accounting has recorded the account review for your applied school record changes.' }}</p>
        @if (isset($studentChangeReviewTerm))<p>{{ $studentChangeReviewTerm }} · Account source unavailable. Contact Accounting to restore the term account.</p>@endif
        <p>Review status is separate from your recorded fees and payment clearance. Contact the Registrar about the academic change.</p>
    </section>
@endif
