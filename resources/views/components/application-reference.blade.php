@props(['value'])
@if (filled($value))
    <span class="tala-reference" data-tala-reference>
        <span class="tala-reference-value">{{ $value }}</span>
        <button type="button" class="tala-copy-reference" data-copy-reference="{{ $value }}" aria-label="Copy application reference">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M8 8V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-3M5 8h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2Z"/></svg>
            Copy
        </button>
        <span class="tala-reference-feedback" role="status" aria-live="polite"></span>
    </span>
@else
    <span>Assigned after submission</span>
@endif
