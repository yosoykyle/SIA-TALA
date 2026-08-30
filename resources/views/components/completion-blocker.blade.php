@props(['blocker'])

<article {{ $attributes->class(['rounded-lg border border-warning-300 bg-warning-50 p-3 dark:border-warning-700 dark:bg-warning-950']) }}>
    <p class="font-medium">{{ $blocker['reason'] }}</p>
    <p class="text-sm">Effect: {{ $blocker['consequence'] }}</p>
    <p class="text-sm">
        Source: {{ $blocker['source'] }}{{ filled($blocker['source_ref'] ?? null) ? ' · '.$blocker['source_ref'] : '' }}{{ filled($blocker['source_as_of'] ?? null) ? ' · as of '.\Illuminate\Support\Carbon::parse($blocker['source_as_of'])->timezone('Asia/Manila')->format('M j, Y g:i A') : '' }}
    </p>
    <p class="text-sm">Responsible: {{ $blocker['owner'] }}</p>
    <p class="text-sm">Next step: {{ $blocker['recovery'] }}</p>
</article>
