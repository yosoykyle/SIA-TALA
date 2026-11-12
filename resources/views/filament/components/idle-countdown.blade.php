@auth
    @php
        $idleSeconds = app(\App\Actions\Authentication\UserSessionService::class)->idleTimeoutMinutes(auth()->user()) * 60;
    @endphp
    <link rel="stylesheet" href="{{ asset('css/tala-idle.css').'?v='.filemtime(public_path('css/tala-idle.css')) }}">
    <section class="tala-idle" id="tala-idle" hidden role="alertdialog" aria-modal="true" aria-labelledby="tala-idle-title" aria-describedby="tala-idle-summary" data-phase="calm"
        data-idle-seconds="{{ $idleSeconds }}" data-warning-seconds="60" data-continue-url="{{ route('session.continue') }}"
        data-login-url="{{ \Filament\Facades\Filament::getPanel($panelId)->getLoginUrl() }}"
        data-activity-key="tala.idle.{{ hash('sha256', session()->getId()) }}"
        data-idle-age="{{ max(0, now()->timestamp - (int) session('tala.last_activity_at', now()->timestamp)) }}"
        x-data="{ showing: false }" x-on:tala-idle-open="showing = true" x-on:tala-idle-close="showing = false"
        x-trap.inert.noscroll.noreturn="showing">
        <div class="tala-idle-glow tala-idle-glow-a"></div><div class="tala-idle-glow tala-idle-glow-b"></div><div class="tala-idle-edge"></div>
        <div class="tala-idle-field" aria-hidden="true"></div>
        <div class="tala-idle-stage">
            <div class="tala-idle-dial" aria-hidden="true">
                <svg class="tala-idle-ring" viewBox="0 0 104 104" focusable="false"><circle class="tala-idle-ring-track" cx="52" cy="52" r="48" pathLength="100" /><circle class="tala-idle-ring-left" cx="52" cy="52" r="48" pathLength="100" /></svg>
                <div class="tala-idle-count"><div class="tala-idle-numeral"><span></span><span></span></div><span class="tala-idle-unit">seconds</span></div>
                <svg class="tala-idle-plane" viewBox="0 0 220 120" focusable="false"><path class="tala-idle-plane-trail" d="M6 96 C 46 108, 74 70, 52 56 C 30 42, 22 78, 62 80 C 104 82, 132 46, 168 34" /><path d="M170 22 L 214 8 L 190 46 L 183 33 Z" /><path class="tala-idle-plane-fold" d="M183 33 L 214 8 L 176 30 Z" /></svg>
            </div>
            <div class="tala-idle-copy">
                <p class="tala-idle-mood">Still there?</p>
                <h2 id="tala-idle-title">Signing you out soon</h2>
                <p class="tala-idle-summary" id="tala-idle-summary">You have been away for a while. To protect your account, this session is about to end.</p>
                <div class="tala-idle-actions">
                    <x-filament::button type="button" data-idle-stay>Stay signed in</x-filament::button>
                    <form method="POST" action="{{ route('session.expire') }}" data-idle-expire>
                        @csrf
                        <input type="hidden" name="panel" value="{{ $panelId }}">
                        <x-filament::button type="submit" color="gray">Sign out now</x-filament::button>
                    </form>
                </div>
                <p class="tala-idle-hint">Or just move, tap or press any key.</p>
                <p class="tala-idle-feedback" role="status" data-idle-feedback hidden></p>
                <p class="sr-only" aria-live="assertive" data-idle-live></p>
            </div>
        </div>
    </section>
    <script src="{{ asset('js/tala-idle.js').'?v='.filemtime(public_path('js/tala-idle.js')) }}" data-navigate-once></script>
@endauth
