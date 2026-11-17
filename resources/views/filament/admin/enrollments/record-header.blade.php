@php
    $student = $case->studentProfile;
    $studentName = $student
        ? collect([$student->first_name, $student->middle_name, $student->last_name])->filter()->implode(' ')
        : \App\Actions\Finance\AccountingDisplay::learnerName($case, 'Student record');
    $studentInitials = \App\Actions\Finance\AccountingDisplay::learnerInitials($case);
    $historyActive = $this->enrollmentView === 'history';
@endphp
<x-record-identity :name="$studentName" :number="\App\Actions\Finance\AccountingDisplay::learnerIdentifier($case)"
    :program="\App\Actions\Finance\AccountingDisplay::learnerProgram($case, '') ?: null"
    :initials="$studentInitials" :photo-url="$student?->adminPhotoUrl()" class="tala-enrollment-record-identity">
    @if ($student)
        <x-slot:actions><x-filament::badge :color="$student->lifecycle_status === \App\Models\StudentProfile::LifecycleActive ? 'success' : 'gray'">{{ \App\Models\StudentProfile::lifecycleStatusLabel($student->lifecycle_status) }} student</x-filament::badge></x-slot:actions>
    @endif
</x-record-identity>
<div class="tala-enrollment-record-context tala-task-surface">
    <div><x-filament::icon icon="heroicon-o-calendar-days" /><strong>{{ collect([$case->term?->label, $case->term?->academicYear?->label])->filter()->implode(' · ') ?: 'Term not recorded' }}</strong></div>
    <x-filament::tabs label="Enrollment record views">
        <x-filament::tabs.item tag="a" :href="$this->enrollmentUrl()" :active="! $historyActive" icon="heroicon-o-document-text">Current enrollment</x-filament::tabs.item>
        <x-filament::tabs.item tag="a" :href="$this->enrollmentUrl(['view' => 'history'])" :active="$historyActive" icon="heroicon-o-clock">History</x-filament::tabs.item>
    </x-filament::tabs>
</div>
