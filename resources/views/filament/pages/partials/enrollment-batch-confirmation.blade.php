<div class="accounting-proof-context">
    <strong>{{ $records->count() }} selected {{ $records->count() === 1 ? 'case' : 'cases' }}</strong>
    @foreach ($records as $case)<p>{{ $case->credentialUser?->getFilamentName() }} &middot; {{ $case->case_reference }}<br>{{ $case->term?->label }}</p>@endforeach
</div>
<p>Email delivery is tracked separately. Review each result before retrying.</p>
