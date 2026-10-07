<span id="tala-main-content" tabindex="-1"></span>
<x-tala-context-entry-notice />
<x-filament::modal
    id="tala-sign-out"
    width="md"
    heading="Sign out?"
    description="Save your work before signing out. Any unsaved changes may be lost."
    icon="heroicon-o-arrow-right-on-rectangle"
    icon-color="warning"
>
    <x-slot name="footer">
        <form method="POST" action="{{ filament()->getLogoutUrl() }}" class="fi-modal-footer-actions">
            @csrf
            <x-filament::button color="gray" outlined type="button" x-on:click="$dispatch('close-modal', { id: 'tala-sign-out' })" autofocus>
                Stay signed in
            </x-filament::button>
            <x-filament::button color="warning" type="submit">
                Sign out
            </x-filament::button>
        </form>
    </x-slot>
</x-filament::modal>
@if (request()->is('admin/admission-applications*') || request()->is('admin/admissions/admission-applications*'))
    <div
        x-data="{
            failed: false,
            unsubscribe: null,
            init() {
                const register = () => {
                    this.unsubscribe = window.Livewire.interceptRequest(({ onError, onFailure, onSuccess }) => {
                        onError(({ preventDefault }) => {
                            preventDefault();
                            this.failed = true;
                        });
                        onFailure(() => { this.failed = true; });
                        onSuccess(() => { this.failed = false; });
                    });
                };
                if (window.Livewire) {
                    register();
                } else {
                    document.addEventListener('livewire:init', register, { once: true });
                }
            },
            destroy() { this.unsubscribe?.(); }
        }"
    >
        <div x-cloak x-show="failed" role="alert" class="mb-4">
            <x-filament::section heading="The latest request could not be confirmed">
                <p>Keep this page open to preserve entered work. Check current records before repeating an action. Sign in again if your session has expired.</p>
                <div class="mt-4">
                    <x-filament::button tag="a" x-bind:href="window.location.href" :href="\App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications::getUrl(request()->query())">
                        Reload workbench
                    </x-filament::button>
                </div>
            </x-filament::section>
        </div>
    </div>
@endif
<div
    role="status"
    aria-live="polite"
    class="sr-only tala-status-announcement"
    x-data="{
        statusMessage: 'Ready',
        unsubscribe: null,
        pendingAnnouncement: null,
        register: null,
        init() {
            this.register = () => {
                if (typeof window.Livewire !== 'undefined') {
                    this.unsubscribe = window.Livewire.hook('commit', ({ component, succeed }) => {
                        const page = this.$el.closest('.fi-main')?.querySelector('.fi-page');
                        const workspaceSearch = component.el?.matches('.fi-global-search-ctn');
                        if (! this.$el.isConnected || ! component.el?.isConnected || (! page?.contains(component.el) && ! workspaceSearch)) return;
                        succeed(() => {
                            clearTimeout(this.pendingAnnouncement);
                            this.pendingAnnouncement = setTimeout(() => {
                                if (! this.$el.isConnected || ! component.el?.isConnected) return;
                                const searchVal = component.el.querySelector('.fi-ta-search-field input')?.value?.trim();
                                const activeTab = component.el.querySelector('.fi-tabs-item.fi-active, [role=tab][aria-selected=true]')?.innerText?.trim();
                                const pageTitle = page?.querySelector('h1')?.innerText?.trim() || document.title;
                                if (workspaceSearch) {
                                    this.statusMessage = 'Workspace page results updated';
                                } else if (searchVal) {
                                    this.statusMessage = `Table filtered by: ${searchVal}`;
                                } else if (activeTab) {
                                    this.statusMessage = `View updated: ${activeTab}`;
                                } else {
                                    this.statusMessage = `View updated: ${pageTitle}`;
                                }
                            }, 50);
                        });
                    });
                }
            };
            if (typeof window.Livewire !== 'undefined') {
                this.register();
            } else {
                document.addEventListener('livewire:init', this.register, { once: true });
            }
        },
        destroy() {
            this.unsubscribe?.();
            clearTimeout(this.pendingAnnouncement);
            document.removeEventListener('livewire:init', this.register);
        }
    }"
    x-text="statusMessage"
>
    Ready
</div>

@once
    <script src="{{ asset('js/tala-reference.js') }}" defer></script>
@endonce
