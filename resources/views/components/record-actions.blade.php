<div {{ $attributes->class(['tala-record-actions', 'tala-record-actions--grouped']) }} role="group" aria-label="Actions for selected record">
    <div class="tala-record-actions-main">{{ $slot }}</div>
    @isset($secondary)<div class="tala-record-actions-secondary">{{ $secondary }}</div>@endisset
</div>
