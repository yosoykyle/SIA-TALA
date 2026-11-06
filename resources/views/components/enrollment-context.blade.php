@props(['context', 'termLabel', 'official' => false, 'compact' => false])
<div {{ $attributes->class(['tala-enrollment-context', 'tala-enrollment-context--compact' => $compact]) }}>
    <dl class="tala-enrollment-context__facts">
        @unless ($compact)<div><dt>Term</dt><dd>{{ $termLabel }}</dd></div>@endunless
        @unless ($compact)<div><dt>Enrollment status</dt><dd><x-filament::badge :color="$official ? 'success' : 'gray'">{{ $context['enrollment_status_label'] }}</x-filament::badge></dd></div>@endunless
        <div><dt>Curriculum level</dt><dd>{{ $context['curriculum_level_label'] }}</dd></div>
        <div><dt>Subject selection</dt><dd>{{ $context['enrollment_type_label'] }}</dd></div>
        <div><dt>Delivery mode</dt><dd>{{ $context['course_delivery_mix'] }}</dd></div>
        @unless ($compact)<div><dt>Student section</dt><dd>{{ $context['student_section_label'] ?? 'Not assigned' }}</dd></div>@endunless
    </dl>
    @if (! $compact && ! $context['student_section_id'])<p class="tala-profile-muted">A section groups students for the term. Their enrolled subjects determine the classes they attend.</p>@endif
</div>
