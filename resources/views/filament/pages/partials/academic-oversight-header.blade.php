<header class="oversight-header">
    <div>
        <h1>Academic Oversight</h1>
        <p class="oversight-readonly"><x-heroicon-o-lock-closed aria-hidden="true" /><span>Read-only · Records maintained by Registrar</span></p>
    </div>
    @if ($this->termId !== null)
        <div class="oversight-term-picker">{{ $this->termSelection }}</div>
    @endif
</header>
