@php
    $user = auth()->user();
    $userName = null;
    $userEmail = null;
    $userInitials = null;
    $userPhotoUrl = null;
    $userIdentifier = null;
    $userIdentifierLabel = null;

    if ($user instanceof \App\Models\User) {
        $user->loadMissing(['staffAccessProfile', 'studentProfile', 'currentAdmissionApplication']);
        $userName = \App\Filament\Resources\Users\Tables\UsersTable::displayName($user);
        $userEmail = (string) $user->email;
        $userInitials = \App\Filament\Resources\Users\Tables\UsersTable::initials($user);
        $userPhotoUrl = $user->getFilamentAvatarUrl();

        if ($user->isStaffCapable() && filled($user->staffAccessProfile?->staff_identifier)) {
            $userIdentifier = (string) $user->staffAccessProfile->staff_identifier;
            $userIdentifierLabel = 'Staff ID';
        } elseif ($user->hasRole('student') && filled($user->studentProfile?->student_number)) {
            $userIdentifier = (string) $user->studentProfile->student_number;
            $userIdentifierLabel = 'Student number';
        } elseif (filled($user->currentAdmissionApplication?->application_reference)) {
            $userIdentifier = (string) $user->currentAdmissionApplication->application_reference;
            $userIdentifierLabel = 'Application reference';
        }
    }

    $roleDescriptions = [
        \App\Models\User::StaffRoleSystemSuperAdmin => 'Manage user accounts, public content, system health, and audit logs.',
        \App\Models\User::StaffRoleRegistrar => 'Review admissions, manage enrollments, curricula, terms, and student records.',
        \App\Models\User::StaffRoleAccounting => 'Configure fee plans, verify payments, and manage student accounts.',
        \App\Models\User::StaffRoleFaculty => 'View teaching schedules, set availability, and submit class grades.',
        \App\Models\User::StaffRoleAcademicHead => 'Review academic approvals, curricula, timetables, and student progress.',
        'student' => 'Check enrollment, class schedule, grades, and tuition account.',
        'applicant' => 'Complete your admission application and track review updates.',
    ];

    $roleTones = [
        \App\Models\User::StaffRoleSystemSuperAdmin => 'danger',
        \App\Models\User::StaffRoleAccounting => 'warning',
        \App\Models\User::StaffRoleAcademicHead => 'warning',
        \App\Models\User::StaffRoleRegistrar => 'primary',
        \App\Models\User::StaffRoleFaculty => 'primary',
        'student' => 'neutral',
        'applicant' => 'neutral',
    ];
@endphp

<x-tala-access-layout title="Choose workspace" heading-id="chooser-heading" :wide="true">
    <div class="tala-access-heading-block">
        <h1 id="chooser-heading">Choose a workspace</h1>
        <p class="tala-access-muted mt-1.5 text-sm">Select which authorized workspace to open for this session.</p>
    </div>

    @if ($userName !== null && $userEmail !== null)
        <x-record-identity
            :name="$userName"
            :program="$userEmail"
            :number="$userIdentifier"
            :number-label="$userIdentifierLabel"
            :show-number-label="filled($userIdentifierLabel)"
            :initials="$userInitials"
            role="group"
            aria-label="Signed-in account"
            class="tala-record-identity--access"
        >
            <x-slot:avatar>
                <div class="student-profile-avatar" aria-hidden="true">
                    @if ($userPhotoUrl)
                        <img
                            src="{{ $userPhotoUrl }}"
                            alt=""
                            onerror="this.hidden = true; if (this.nextElementSibling) { this.nextElementSibling.hidden = false; }"
                        >
                        <span hidden>{{ $userInitials }}</span>
                    @else
                        {{ $userInitials }}
                    @endif
                </div>
            </x-slot:avatar>
        </x-record-identity>
    @endif

    <x-tala-context-entry-notice />

    @error('context')
        <x-tala-access-alert variant="danger" class="mt-4" role="alert">
            <p>{{ $message }} Review the current choices or ask a System Administrator to check your access.</p>
        </x-tala-access-alert>
    @enderror

    @if (! empty($contexts))
        <div class="tala-workspace-grid">
            @foreach ($contexts as $key => $context)
                @php
                    $tone = $roleTones[$key] ?? 'neutral';
                    $description = $roleDescriptions[$key] ?? 'Open this authorized workspace.';
                @endphp
                <form method="POST" action="{{ route('workspace-chooser.select') }}" class="tala-workspace-form">
                    @csrf
                    <input type="hidden" name="context" value="{{ $key }}">
                    <button type="submit" class="tala-workspace-choice">
                        <span class="tala-workspace-choice__icon tala-workspace-choice__icon--{{ $tone }}" aria-hidden="true">
                            @switch($key)
                                @case(\App\Models\User::StaffRoleSystemSuperAdmin)
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                                    @break
                                @case(\App\Models\User::StaffRoleAccounting)
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" /></svg>
                                    @break
                                @case(\App\Models\User::StaffRoleAcademicHead)
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z" /></svg>
                                    @break
                                @case(\App\Models\User::StaffRoleRegistrar)
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75" /></svg>
                                    @break
                                @case(\App\Models\User::StaffRoleFaculty)
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" /></svg>
                                    @break
                                @case('student')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                                    @break
                                @default
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" /></svg>
                            @endswitch
                        </span>
                        <span class="tala-workspace-choice__body">
                            <span class="tala-workspace-choice__title">{{ $context['label'] }}</span>
                            <span class="tala-workspace-choice__desc">{{ $description }}</span>
                        </span>
                        <svg class="tala-workspace-choice__chevron" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </form>
            @endforeach
        </div>
    @else
        <div class="tala-workspace-empty-panel mt-6" role="status">
            <h2 class="text-base font-semibold">No workspace is currently authorized</h2>
            <p class="mt-2 text-sm leading-relaxed">Your current account records provide no active workspace assignment. Ask a System Administrator to review your account access. System Administration manages account roles and workspace access; this selection screen cannot grant access permissions.</p>
            <p class="mt-3 text-sm tala-access-muted">To request access, contact System Administration or use school support below. You may also sign out and switch accounts.</p>
        </div>
    @endif

    <div class="tala-access-footer-actions">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="tala-access-secondary-btn">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                </svg>
                <span>Sign out</span>
            </button>
        </form>
    </div>
</x-tala-access-layout>
