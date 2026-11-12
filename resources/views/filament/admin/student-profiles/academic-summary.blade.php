<div class="space-y-4">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-academic-average :average="$cumulative" />
        <dl class="space-y-2">
            <dt class="text-sm text-gray-600 dark:text-gray-300">{{ $effect['scope_label'] }}</dt>
            <dd><x-filament::badge color="gray">{{ $effect['label'] }}</x-filament::badge></dd>
            <dd class="text-sm">{{ $effect['student_reason'] }}</dd>
            <dd class="text-sm text-gray-600 dark:text-gray-300">{{ $effect['context_reason'] ?? 'The Registrar confirms the next subject selection. Payment clearance is separate.' }}</dd>
        </dl>
    </div>
    <x-supporting-details summary="Term averages and sources" inset>
        <div class="space-y-4">
            @forelse ($termAverages as $item)
                <div class="space-y-1">
                    <h3 class="font-semibold">{{ $item['term']->academicYear?->label }} · {{ $item['term']->label }}</h3>
                    <x-academic-average :average="$item['average']" />
                    @foreach ($item['average']['exclusions'] as $exclusion)<p class="text-sm text-gray-600 dark:text-gray-300">{{ $exclusion }}</p>@endforeach
                </div>
            @empty <p class="text-sm">No official course registrations are recorded.</p> @endforelse
            <p class="text-sm text-gray-600 dark:text-gray-300">As of {{ \Illuminate\Support\Carbon::parse($cumulative['as_of'])->timezone('Asia/Manila')->format('M j, Y g:i A') }} · Asia/Manila<br>{{ $effect['source'] }} · {{ $curriculum['completed_units'] }} completed curriculum units · {{ $curriculum['deficiency_count'] }} remaining requirements</p>
        </div>
    </x-supporting-details>
</div>
