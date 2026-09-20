<span id="tala-main-content" tabindex="-1"></span>
<x-tala-context-entry-notice />
<div
    role="status"
    aria-live="polite"
    class="sr-only tala-status-announcement"
    x-data="{
        statusMessage: 'Ready',
        init() {
            const register = () => {
                if (typeof window.Livewire !== 'undefined') {
                    window.Livewire.hook('commit', ({ component, succeed }) => {
                        succeed(() => {
                            setTimeout(() => {
                                const searchVal = document.querySelector('.fi-ta-search-field input, input[type=search]')?.value?.trim();
                                const activeTab = document.querySelector('.fi-tabs-item.fi-active, [aria-selected=true]')?.innerText?.trim();
                                const pageTitle = document.querySelector('h1')?.innerText?.trim() || document.title;
                                if (searchVal) {
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
                register();
            } else {
                document.addEventListener('livewire:init', register, { once: true });
            }
        }
    }"
    x-text="statusMessage"
>
    Ready
</div>
