<x-filament-panels::page>
    <div class="tala-workspace-launcher">
        <p class="tala-workspace-launcher__scope">Across all terms</p>
        <nav class="tala-workspace-launcher__main tala-workspace-launcher__main--two-up" aria-label="Admissions work">
            <x-workspace-card :href="\App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications::getUrl(['tab' => \App\Queries\Admissions\AdmissionQueueCounts::NeedsReview])" label="Applications to review"
                :figure="$queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::NeedsReview]" :figure-label="\Illuminate\Support\Str::plural('application', $queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::NeedsReview]).' awaiting review'"
                action="Review applications">
                <x-slot:art>
                    <svg viewBox="0 0 140 140" aria-hidden="true">
                        <rect x="40" y="16" width="70" height="92" rx="9" transform="rotate(7 75 62)" style="fill:var(--tala-text);opacity:.08" />
                        <rect x="32" y="12" width="70" height="92" rx="9" style="fill:var(--tala-surface-sunken);stroke:var(--tala-edge)" />
                        <rect x="46" y="30" width="42" height="6" rx="3" style="fill:var(--tala-text);opacity:.38" />
                        <rect x="46" y="44" width="30" height="5" rx="2.5" style="fill:var(--tala-text);opacity:.2" />
                        <rect x="46" y="55" width="36" height="5" rx="2.5" style="fill:var(--tala-text);opacity:.2" />
                        <rect x="46" y="66" width="24" height="5" rx="2.5" style="fill:var(--tala-text);opacity:.2" />
                        <circle cx="98" cy="96" r="24" style="fill:var(--tala-brand)" />
                        <path d="M107.3 105.3l-5.2-5.2m0 0a6 6 0 1 0-8.49-8.49 6 6 0 0 0 8.49 8.49z" style="stroke:var(--tala-on-brand);fill:none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </x-slot:art>
                @if ($queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::NeedsReview] === 0)<p class="tala-workspace-card__note">No applications waiting.</p>@endif
                <a class="tala-workspace-text-link" {{ \Filament\Support\generate_href_html(\App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications::getUrl(['tab' => \App\Queries\Admissions\AdmissionQueueCounts::Closed])) }}>Find a closed application <x-filament::icon icon="heroicon-m-arrow-right" /></a>
            </x-workspace-card>
            <x-workspace-card :href="\App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications::getUrl(['tab' => \App\Queries\Admissions\AdmissionQueueCounts::RegistrarClearance])" label="Application clearance"
                :figure="$queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::RegistrarClearance]" :figure-label="\Illuminate\Support\Str::plural('application', $queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::RegistrarClearance]).' ready to be cleared'"
                action="Clear applications">
                <x-slot:art>
                    <svg viewBox="0 0 140 140" aria-hidden="true">
                        <rect x="40" y="16" width="70" height="92" rx="9" transform="rotate(-7 75 62)" style="fill:var(--tala-text);opacity:.08" />
                        <rect x="38" y="12" width="70" height="92" rx="9" style="fill:var(--tala-surface-sunken);stroke:var(--tala-edge)" />
                        <rect x="52" y="30" width="42" height="6" rx="3" style="fill:var(--tala-text);opacity:.38" />
                        <rect x="52" y="44" width="30" height="5" rx="2.5" style="fill:var(--tala-text);opacity:.2" />
                        <rect x="52" y="55" width="36" height="5" rx="2.5" style="fill:var(--tala-text);opacity:.2" />
                        <rect x="52" y="66" width="24" height="5" rx="2.5" style="fill:var(--tala-text);opacity:.2" />
                        <circle cx="98" cy="96" r="24" style="fill:var(--tala-brand)" />
                        <path d="M89 96.5l6 6 13-14" style="stroke:var(--tala-on-brand);fill:none" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </x-slot:art>
                @if ($queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::RegistrarClearance] === 0)<p class="tala-workspace-card__note">No applications waiting on clearance.</p>@endif
                <a class="tala-workspace-text-link" {{ \Filament\Support\generate_href_html(\App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications::getUrl(['tab' => \App\Queries\Admissions\AdmissionQueueCounts::ReadyForEnrollment])) }}>Find a cleared application <x-filament::icon icon="heroicon-m-arrow-right" /></a>
            </x-workspace-card>
        </nav>
        <section class="tala-workspace-launcher__supporting" aria-labelledby="other-admissions-title">
            <h2 id="other-admissions-title">Other queues</h2>
            <nav class="tala-task-surface tala-workspace-launcher__other tala-workspace-launcher__other--plain-list" aria-label="Other Admissions queues">
                <x-workspace-card compact :href="\App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications::getUrl(['tab' => \App\Queries\Admissions\AdmissionQueueCounts::WaitingForApplicant])" icon="heroicon-o-clock" label="Waiting for applicant" description="Corrections sent back; the applicant acts next." :count="$queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::WaitingForApplicant]" />
                <x-workspace-card compact :href="\App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications::getUrl(['tab' => \App\Queries\Admissions\AdmissionQueueCounts::ReadyForEnrollment])" icon="heroicon-o-check-circle" label="Ready for enrollment" description="Cleared applicants tracking toward official enrollment." :count="$queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::ReadyForEnrollment]" />
                <x-workspace-card compact :href="\App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications::getUrl(['tab' => \App\Queries\Admissions\AdmissionQueueCounts::Closed])" icon="heroicon-o-archive-box" label="Closed applications" description="Withdrawn or not admitted; terminal records only." :count="$queueCounts[\App\Queries\Admissions\AdmissionQueueCounts::Closed]" />
            </nav>
        </section>
    </div>
</x-filament-panels::page>
