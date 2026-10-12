<div class="space-y-4">
    <div><h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</h3><p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $meeting['courseCode'] }} · {{ $meeting['class'] }}</p></div>
    <dl class="space-y-3 text-base">
        @foreach (['When' => $meeting['dayLabel'].' · '.$meeting['time'], 'Location' => $meeting['room']] as $label => $value)
            <div><dt class="font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="mt-1 text-gray-950 dark:text-white">{{ $value }}</dd></div>
        @endforeach
    </dl>
    @if ($showFaculty ?? false)<p class="text-sm">Teacher: {{ $meeting['faculty'] }}</p>@endif
    <p class="border-t border-gray-200 pt-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">{{ $meeting['component'] }} · {{ $meeting['modality'] }} · {{ $meeting['duration'] }} · {{ $meeting['status'] }}</p>
    @if ($meeting['offGrid'])<p class="text-sm">This meeting is outside the recorded teaching grid.</p>@endif
</div>
