@php
    $tabDescriptions = [
        \App\Actions\SystemAdministration\GovernanceEvidenceProjection::InstitutionalChanges => 'Records academic, curriculum, admissions, enrollment, grade, finance, content, and access changes across TALA.',
        \App\Actions\SystemAdministration\GovernanceEvidenceProjection::SystemEvents => 'Records sign-ins, MFA and password actions, mail self-tests, solver probes, and queue recovery runs.',
        \App\Actions\SystemAdministration\GovernanceEvidenceProjection::OutputAccess => 'Records authorized views, prints, and official exports of COR, TOR, and registrar reports.',
        \App\Actions\SystemAdministration\GovernanceEvidenceProjection::PrivacyRetention => 'States TALA read-only data preservation boundaries and manual institutional records disposal rules.',
    ];
@endphp

<x-filament-panels::page>
    <div
        class="space-y-4"
        x-data="{
            selected: @js($activeTab),
            focused: @js($activeTab),
            tabs: @js(array_keys($this->tabs)),
            descriptions: @js($tabDescriptions),
            focusNext(current) {
                let index = this.tabs.indexOf(current);
                let next = this.tabs[(index + 1) % this.tabs.length];
                this.focusTab(next);
            },
            focusPrev(current) {
                let index = this.tabs.indexOf(current);
                let prev = this.tabs[(index - 1 + this.tabs.length) % this.tabs.length];
                this.focusTab(prev);
            },
            focusFirst() {
                this.focusTab(this.tabs[0]);
            },
            focusLast() {
                this.focusTab(this.tabs[this.tabs.length - 1]);
            },
            focusTab(key) {
                this.focused = key;
                this.$nextTick(() => {
                    let el = document.getElementById('tab-' + key);
                    if (el) el.focus();
                });
            },
            selectTab(key) {
                this.focused = key;
                this.selected = key;
                $wire.call('setActiveTab', key);
                this.$nextTick(() => {
                    let el = document.getElementById('tab-' + key);
                    if (el) el.focus();
                });
            }
        }"
        x-init="$watch('$wire.activeTab', value => { if (value) { selected = value; focused = value; } })"
    >
        <div class="tala-work-tabs tala-work-tabs--full">
            <x-tala-navigation
                label="Governance evidence views"
                compact
                stretch
                role="tablist"
                class="tala-work-tabs__nav"
            >
                @foreach ($this->tabs as $key => $label)
                    <x-filament::tabs.item
                        :active="$activeTab === $key"
                        id="tab-{{ $key }}"
                        role="tab"
                        aria-controls="tabpanel-governance"
                        x-bind:aria-selected="selected === '{{ $key }}' ? 'true' : 'false'"
                        aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                        x-bind:tabindex="focused === '{{ $key }}' ? '0' : '-1'"
                        tabindex="{{ $activeTab === $key ? '0' : '-1' }}"
                        x-bind:class="{ 'fi-active': selected === '{{ $key }}' }"
                        @click="selectTab('{{ $key }}')"
                        @keydown.enter.prevent="selectTab('{{ $key }}')"
                        @keydown.space.prevent="selectTab('{{ $key }}')"
                        @keydown.arrow-right.prevent="focusNext('{{ $key }}')"
                        @keydown.arrow-left.prevent="focusPrev('{{ $key }}')"
                        @keydown.home.prevent="focusFirst()"
                        @keydown.end.prevent="focusLast()"
                        class="min-h-11 shrink-0 whitespace-nowrap"
                    >
                        {{ $label }}
                    </x-filament::tabs.item>
                @endforeach
            </x-tala-navigation>
        </div>

        <p
            class="rounded-lg border px-3.5 py-2 text-xs"
            style="border-color: var(--tala-edge); background: var(--tala-surface-subtle); color: var(--tala-muted);"
            role="note"
            x-text="descriptions[selected] ?? ''"
        >
            {{ $tabDescriptions[$activeTab] ?? '' }}
        </p>

        <div
            id="tabpanel-governance"
            role="tabpanel"
            :aria-labelledby="'tab-' + selected"
            aria-labelledby="tab-{{ $activeTab }}"
            tabindex="0"
            class="focus:outline-none"
        >
            @if ($activeTab === \App\Actions\SystemAdministration\GovernanceEvidenceProjection::PrivacyRetention)
                <div class="space-y-4">
                    <section class="tala-task-surface rounded-xl p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold" style="color: var(--tala-text);">
                                    Privacy &amp; retention boundary
                                </h2>
                                <p class="mt-1 text-sm" style="color: var(--tala-muted);">
                                    This view states the implemented MVP boundary; it does not issue a compliance verdict.
                                </p>
                            </div>
                            <x-filament::badge color="gray" size="sm">Read-only boundary</x-filament::badge>
                        </div>

                        <div
                            class="mt-4 grid gap-4"
                            style="grid-template-columns: repeat(auto-fit, minmax(min(18rem, 100%), 1fr));"
                        >
                            <article class="tala-profile-inset space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold" style="color: var(--tala-text);">
                                        Automatic record disposal
                                    </h3>
                                    <x-filament::badge color="gray" size="sm">Not provided in this MVP</x-filament::badge>
                                </div>
                                <p class="text-sm font-medium" style="color: var(--tala-text);">
                                    Automatic retention disposal: Not provided in this MVP
                                </p>
                                <p class="text-sm" style="color: var(--tala-muted);">
                                    Automatic record disposal is not available in TALA. Follow the institution's approved privacy and records procedure.
                                </p>
                            </article>

                            <article class="tala-profile-inset space-y-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold" style="color: var(--tala-text);">
                                        External compliance &amp; custody
                                    </h3>
                                    <x-filament::badge color="gray" size="sm">Not evaluated by TALA</x-filament::badge>
                                </div>
                                <p class="text-sm font-medium" style="color: var(--tala-text);">
                                    External compliance status: Not evaluated by TALA
                                </p>
                                <p class="text-sm" style="color: var(--tala-muted);">
                                    Retention schedules, privacy requests, legal holds, lawful disposition, provider approval, custody, and secure disposal remain institutional responsibilities.
                                </p>
                            </article>
                        </div>
                    </section>

                    <section class="tala-task-surface rounded-xl p-5">
                        <h3 class="text-sm font-semibold" style="color: var(--tala-text);">
                            Authoritative record preservation
                        </h3>
                        <p class="mt-1 text-sm" style="color: var(--tala-muted);">
                            TALA preserves the authoritative records and evidence already stored; this page performs no disposal action.
                        </p>
                    </section>
                </div>
            @else
                {{ $this->table }}
            @endif
        </div>
    </div>
</x-filament-panels::page>
