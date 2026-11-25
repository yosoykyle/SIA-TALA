{{-- #99 Scope 11 · Enroll as irregular, step 3: final review before submitting. --}}
<div class="tala-irregular-request-step">
    <dl class="tala-irregular-request-facts" aria-label="Application summary">
        <div><dt>Student</dt><dd>{{ $case->studentProfile?->student_number }} · {{ collect([$case->studentProfile?->first_name, $case->studentProfile?->last_name])->filter()->implode(' ') }}</dd></div>
        <div><dt>Reason</dt><dd>{{ collect($review['reasons'])->map(fn ($reason) => $reason['label'].(($reason['subjects'] ?? []) !== [] ? ' – '.implode(', ', $reason['subjects']) : ''))->implode('; ') ?: $review['status_label'] }}</dd></div>
        <div><dt>Total</dt><dd>{{ $summary }}</dd></div>
    </dl>
    <h3 class="tala-irregular-request-subheading">Selected subjects, sections and schedule</h3>
    @if ($sections->isEmpty())
        <p>No subjects chosen yet. Go back to Choose subjects.</p>
    @else
        @php
            $descriptions = app(\App\Actions\Enrollment\RegistrationClassChoices::class)->sectionDescriptions($case);
        @endphp
        <ul class="tala-irregular-request-subjects">
            @foreach ($sections as $section)
                <li><strong>{{ $section->termOffering?->course()?->code }} – {{ $section->termOffering?->courseSpecification()?->title }}</strong> · {{ $section->code }}<br><span>{{ $descriptions[$section->id] ?? '' }}</span></li>
            @endforeach
        </ul>
    @endif
</div>
