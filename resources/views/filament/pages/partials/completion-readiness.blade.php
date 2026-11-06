    @php
        $student = $readinessReview['student'];
        $projection = $readinessReview['projection'];
        $conferral = $projection['conferral'];
    @endphp
    <div class="space-y-4">
        <p>{{ $student->program?->name }} · {{ $student->curriculumVersion?->name }}</p>
        @if ($conferral)
            <p><strong>Graduation recorded:</strong> {{ $conferral->degree_name }}.
                @if ($conferral->conferred_on)External graduation date: {{ $conferral->conferred_on->format('M j, Y') }}.
                @else External graduation date not recorded.@endif
            </p>
        @endif
        @forelse ($projection['blockers'] as $blocker)
            <x-completion-blocker :blocker="$blocker" />
        @empty
            <p>{{ $conferral ? 'The recorded graduation result remains part of the student history.' : 'Current academic sources are complete. The Registrar can record the actual institutional graduation result.' }}</p>
        @endforelse
        <p class="text-sm">Checked {{ $readinessReview['checkedAt']->format('M j, Y g:i A') }} · Asia/Manila. Readiness is rechecked when graduation is recorded.</p>
    </div>
