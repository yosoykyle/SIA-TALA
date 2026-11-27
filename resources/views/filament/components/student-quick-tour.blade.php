@php
    $tourUserId = auth()->id();
@endphp
@if ($tourUserId !== null)
    <aside
        id="tala-student-tour-invite"
        class="tala-quick-tour-invite"
        data-tala-quick-tour
        data-tour-user-id="{{ $tourUserId }}"
        data-tour-role="student"
        data-tour-version="1"
        aria-labelledby="tala-student-tour-invite-title"
        hidden
    >
        <div class="tala-quick-tour-invite__copy">
            <span class="tala-quick-tour-invite__badge">Quick tour</span>
            <p id="tala-student-tour-invite-title" class="tala-quick-tour-invite__title">
                New to Student Hub? Take a short guided look at your workspace landmarks.
            </p>
        </div>
        <div class="tala-quick-tour-invite__actions">
            <x-filament::button
                size="sm"
                color="primary"
                class="tala-quick-tour-invite__start"
                data-tour-start
            >
                Take a quick tour
            </x-filament::button>
            <x-filament::button
                size="sm"
                color="gray"
                outlined
                class="tala-quick-tour-invite__dismiss"
                data-tour-dismiss
                aria-label="Dismiss quick tour invitation"
            >
                Dismiss
            </x-filament::button>
        </div>
    </aside>
@endif
