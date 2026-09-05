<x-filament-panels::page>
    <div
        class="space-y-6"
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
        <div
            class="flex gap-2 overflow-x-auto rounded-xl border border-gray-200 bg-white p-2 dark:border-white/10 dark:bg-gray-900"
            role="tablist"
            aria-label="Governance evidence views"
        >
            @foreach ($this->tabs as $key => $label)
                <button
                    type="button"
                    id="tab-{{ $key }}"
                    role="tab"
                    aria-controls="tabpanel-governance"
                    :aria-selected="selected === '{{ $key }}' ? 'true' : 'false'"
                    aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                    :tabindex="focused === '{{ $key }}' ? '0' : '-1'"
                    tabindex="{{ $activeTab === $key ? '0' : '-1' }}"
                    @click="selectTab('{{ $key }}')"
                    @keydown.enter.prevent="selectTab('{{ $key }}')"
                    @keydown.space.prevent="selectTab('{{ $key }}')"
                    @keydown.arrow-right.prevent="focusNext('{{ $key }}')"
                    @keydown.arrow-left.prevent="focusPrev('{{ $key }}')"
                    @keydown.home.prevent="focusFirst()"
                    @keydown.end.prevent="focusLast()"
                    :class="selected === '{{ $key }}' ? 'bg-primary-600 text-white' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5'"
                    class="{{ $activeTab === $key ? 'bg-primary-600 text-white' : 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5' }} min-h-11 shrink-0 rounded-lg px-4 py-2 text-sm font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

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

                    <div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
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
