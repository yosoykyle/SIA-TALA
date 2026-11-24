<x-filament-panels::page>
    <div
        class="space-y-4"
        x-data="{
            selected: @js($activeTab),
            focused: @js($activeTab),
            tabs: @js(array_keys($this->tabs)),
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
        <x-filament::tabs
            label="Governance evidence views"
            role="tablist"
            class="mx-0 w-fit max-w-full"
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
                    class="min-h-11 shrink-0"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        <div
            id="tabpanel-governance"
            role="tabpanel"
            :aria-labelledby="'tab-' + selected"
            aria-labelledby="tab-{{ $activeTab }}"
            tabindex="0"
            class="focus:outline-none"
        >
            @if ($activeTab === \App\Actions\SystemAdministration\GovernanceEvidenceProjection::PrivacyRetention)
                <x-filament::section>
                    <x-slot name="heading">Privacy and Retention Boundary</x-slot>
                    <x-slot name="description">This view states the implemented MVP boundary; it does not issue a compliance verdict.</x-slot>

                    <div class="space-y-4 text-sm text-zinc-700 dark:text-zinc-200">
                        <p><strong>Automatic retention disposal: Not provided in this MVP</strong></p>
                        <p><strong>External compliance status: Not evaluated by TALA</strong></p>
                        <p>Automatic record disposal is not available in TALA. Follow the institution's approved privacy and records procedure.</p>
                        <p>
                            Retention schedules, privacy requests, legal holds, lawful disposition, provider approval,
                            custody, and secure disposal remain institutional responsibilities. TALA preserves the
                            authoritative records and evidence already stored; this page performs no disposal action.
                        </p>
                    </div>
                </x-filament::section>
            @else
                {{ $this->table }}
            @endif
        </div>
    </div>
</x-filament-panels::page>
