@php
    $profile = $this->academicProfile;
    $student = $profile['student'];
    $context = $profile['context'];
    $tab = $this->activeRecordTab();
    $name = collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ');
    $initials = mb_substr($student->first_name, 0, 1).mb_substr($student->last_name, 0, 1);
@endphp
<div class="tala-student-profile">
    <x-record-identity :name="$name" :number="$student->student_number" :program="$student->program?->name"
        :initials="$initials" :photo-url="$student->user?->getFilamentAvatarUrl()" heading-level="h1">
        <x-slot:supporting><p class="tala-profile-muted">{{ $student->curriculumVersion?->name ?? 'No curriculum recorded' }}</p></x-slot:supporting>
        <x-slot:actions><x-filament::badge :color="$student->lifecycle_status === \App\Models\StudentProfile::LifecycleActive ? 'success' : 'gray'">{{ \App\Models\StudentProfile::lifecycleStatusLabel($student->lifecycle_status) }} student</x-filament::badge></x-slot:actions>
    </x-record-identity>
    <x-filament::tabs label="Student record views" class="tala-profile-tabs">
        @foreach (['overview' => 'Summary', 'academic' => 'Academic record', 'enrollment' => 'Enrollment', 'requirements' => 'Requirements', 'history' => 'History'] as $key => $label)
            <x-filament::tabs.item :active="$tab === $key" :icon="['overview' => 'heroicon-o-document-text', 'academic' => 'heroicon-o-book-open', 'enrollment' => 'heroicon-o-user-group', 'requirements' => 'heroicon-o-clipboard-document-check', 'history' => 'heroicon-o-clock'][$key]" wire:click="selectRecordTab('{{ $key }}')" wire:loading.attr="disabled" wire:target="selectRecordTab">{{ $label }}</x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>
    <div class="tala-content-region" wire:loading.attr="aria-busy" wire:target="selectRecordTab">
    <x-content-progress label="Loading student record" wire:loading.delay.short.block wire:target="selectRecordTab" />
    @if ($tab === 'overview')
        @if ($profile['handoff'])
            <div class="tala-profile-handoff">
                <p class="tala-profile-muted">Current enrollment · {{ $profile['termLabel'] }}</p>
                <x-workflow-handoff :current-owner="$profile['handoff']['currentOwner']" :current-task="$profile['handoff']['currentTask']" :next-owner="$profile['handoff']['nextOwner']" :next-task="$profile['handoff']['nextTask']" />
            </div>
        @endif
        <div @class(['tala-profile-overview', 'tala-profile-overview--without-enrollment' => ! $context])>
            <x-filament::section icon="heroicon-o-academic-cap" icon-color="primary" compact>
                <x-slot:heading>Current enrollment</x-slot:heading>
                @if ($context)
                    <x-enrollment-context :context="$context" :term-label="$profile['termLabel']" :official="$profile['enrollment']->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled" />
                    @if ($profile['enrollmentUrl'])
                        <x-slot:footer><x-filament::button tag="a" :href="$profile['enrollmentUrl']" icon="heroicon-o-arrow-top-right-on-square" class="tala-profile-full-action">Open enrollment</x-filament::button></x-slot:footer>
                    @endif
                @else
                    <x-filament::empty-state heading="No current enrollment" compact :contained="false" />
                    <x-slot:footer><x-filament::button color="gray" wire:click="selectRecordTab('enrollment')" class="tala-profile-full-action">View enrollments</x-filament::button></x-slot:footer>
                @endif
            </x-filament::section>
            <div class="tala-profile-aside">
                <x-filament::section icon="heroicon-o-shield-check" icon-color="primary" compact>
                    <x-slot:heading>Academic guidance</x-slot:heading>
                    <x-slot:description>Academic standing and restrictions.</x-slot:description>
                    <div class="tala-profile-inset">
                        <strong>{{ $profile['guidance'] }}</strong>
                        @if ($profile['effect']['effect'] !== \App\Models\AcademicDecision::EffectAllowed)<p class="tala-profile-muted">{{ $profile['effect']['reason'] }}</p>@endif
                        <p class="tala-profile-muted">Payment clearance is separate.</p>
                    </div>
                    <x-slot:footer><x-filament::button color="gray" icon="heroicon-o-book-open" wire:click="selectRecordTab('academic')" class="tala-profile-full-action">View academic record</x-filament::button></x-slot:footer>
                </x-filament::section>
                <x-filament::section icon="heroicon-o-clipboard-document-check" icon-color="primary" compact>
                    <x-slot:heading>Requirements & follow-up</x-slot:heading>
                    <x-slot:description>Documents and office follow-up.</x-slot:description>
                    <div class="tala-profile-inset">
                        <strong>{{ $profile['holds']->isEmpty() ? 'No active restrictions recorded' : $profile['holds']->count().' active '.\Illuminate\Support\Str::plural('restriction', $profile['holds']->count()) }}</strong>
                        <p class="tala-profile-muted">Review the document checklist and any unresolved restrictions.</p>
                    </div>
                    <x-slot:footer><x-filament::button color="gray" icon="heroicon-o-clipboard-document-check" wire:click="selectRecordTab('requirements')" class="tala-profile-full-action">View requirements</x-filament::button></x-slot:footer>
                </x-filament::section>
            </div>
        </div>
    @else
        @include('filament.admin.student-profiles.'.$tab)
    @endif
    </div>
</div>
