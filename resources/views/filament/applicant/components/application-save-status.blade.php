<div class="tala-draft-status" role="status" aria-live="polite" aria-atomic="true">
    @if ($this->saveStatus === 'failed')
        <span class="tala-draft-status-message">{{ $this->saveStatusMessage }}</span>
    @else
        <span x-cloak x-show="changed" data-draft-dirty class="tala-draft-status-message">Unsaved changes</span>
        <span x-show="! changed" class="tala-draft-status-message">{{ $this->saveStatusMessage }}</span>
    @endif
</div>
