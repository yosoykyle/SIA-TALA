<div class="space-y-2">
    {{ $this->termSelection }}
    @if ($terms->isEmpty())
        <p class="text-sm text-gray-600 dark:text-gray-300">No Term is available. Contact the Registrar.</p>
    @endif
</div>