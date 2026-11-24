@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets

<div class="registrar-ready-applicants">
    <div class="registrar-ready-toolbar">
        <div class="registrar-ready-search">
            <label for="ready-applicant-search">Find an applicant</label>
            <x-filament::input.wrapper>
                <x-filament::input id="ready-applicant-search" type="search" wire:model.live.debounce.300ms="readyApplicantSearch" placeholder="Name or application number" />
            </x-filament::input.wrapper>
        </div>
        <x-filament::link :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => 'all_cases'])">Find an enrollment</x-filament::link>
    </div>
    <ul class="registrar-ready-list" aria-label="Ready applicants">
        @forelse ($applications as $application)
            <li wire:key="ready-applicant-{{ $application->id }}">
                <div>
                    <h2>{{ collect([$application->first_name, $application->middle_name, $application->last_name])->filter()->implode(' ') }}</h2>
                    <x-student-type-badge :path="$application->application_path" />
                    <p class="registrar-ready-reference">{{ $application->application_reference }}</p>
                </div>
                <div class="registrar-ready-studies">
                    <p>{{ $application->program?->name ?? 'Program not assigned' }}</p>
                    <p>{{ $application->term?->label ?? 'Term not assigned' }}</p>
                </div>
                <div class="registrar-ready-action">
                        <x-filament::button
                            type="button"
                            aria-label="Start enrollment for {{ collect([$application->first_name, $application->middle_name, $application->last_name])->filter()->implode(' ') }}"
                            wire:click="startRegistrationForApplicant({{ $application->id }}, {{ $application->term_id ?? 'null' }})"
                            wire:loading.attr="disabled"
                            color="primary"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                        >
                            Start enrollment
                        </x-filament::button>
                </div>
            </li>
        @empty
            <li class="registrar-ready-empty">
                <x-servitech-empty-state
                    :illustrated="blank($this->readyApplicantSearch)"
                    :heading="filled($this->readyApplicantSearch) ? 'No matching applicants' : 'No applicants waiting to start'"
                    :description="filled($this->readyApplicantSearch) ? 'Try another name or application number, or clear the search.' : 'Applicants appear after Admissions clears them for enrollment. Existing cases and individual adjustments are in Enrollment records.'"
                    :icon="filled($this->readyApplicantSearch) ? 'heroicon-o-magnifying-glass' : 'heroicon-o-user-plus'"
                >
                    @if (filled($this->readyApplicantSearch))
                        <x-filament::button color="gray" wire:click="$set('readyApplicantSearch', '')" icon="heroicon-m-x-mark">Clear search</x-filament::button>
                    @else
                        {{ $this->getAction('startContinuingEnrollment', isMounting: false) }}
                        {{ $this->getAction('startLateContinuingEnrollment', isMounting: false) }}
                        <x-filament::button tag="a" color="gray" icon="heroicon-o-magnifying-glass" :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => 'all_cases'])">Irregular or existing enrollment</x-filament::button>
                    @endif
                </x-servitech-empty-state>
            </li>
        @endforelse
    </ul>
    @if ($applications->total() > 0)
        <x-filament::pagination :paginator="$applications" />
    @endif
</div>

<x-filament-actions::modals />
