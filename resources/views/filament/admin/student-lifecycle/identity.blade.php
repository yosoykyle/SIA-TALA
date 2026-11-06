@if ($student)
    <x-record-identity
        :name="trim($student->first_name . ' ' . $student->last_name)"
        :number="$student->student_number"
        :program="$student->program?->name"
        :photo-url="$student->user?->getFilamentAvatarUrl()"
        :initials="mb_strtoupper(mb_substr($student->first_name, 0, 1) . mb_substr($student->last_name, 0, 1))"
        class="tala-lifecycle-identity tala-record-identity--compact"
    >
        <x-slot:actions>
        <div class="tala-lifecycle-identity-actions">
            <span>{{ \App\Models\StudentProfile::lifecycleStatusLabel($student->lifecycle_status) }} student</span>
        </div>
        </x-slot:actions>
    </x-record-identity>
@endif
