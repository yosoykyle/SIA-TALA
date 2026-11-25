@php
    $profile = $this->profileData;
    $hasPrimaryBarActions = $this->resendInvitationAction->isVisible()
        || $this->correctPendingEmailAction->isVisible()
        || $this->resendVerificationAction->isVisible()
        || $this->reactivateAction->isVisible()
        || $this->editRoleAndDetailsAction->isVisible()
        || $this->changeSignInEmailAction->isVisible()
        || $this->sendRecoveryLinkAction->isVisible();
    $hasDangerBarActions = $this->resetMfaAction->isVisible()
        || $this->cancelInvitationAction->isVisible()
        || $this->disableAction->isVisible();
@endphp

<div class="tala-student-profile" style="gap: var(--tala-card-gap);">
    <div class="tala-task-surface overflow-hidden">
        <x-record-identity
            :name="$profile['display_name']"
            :program="$profile['email']"
            :number="$profile['identity_pill']"
            :initials="$profile['initials']"
            heading-level="h1"
            class="border-0 rounded-none"
        >
            <x-slot:status>
                <x-filament::badge :color="$profile['status_color']">
                    {{ $profile['status_label'] }}
                </x-filament::badge>
            </x-slot:status>

            @if ($profile['status_color'] !== 'success' && filled($profile['next_action'] ?? null))
                <x-slot:supporting>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        {{ $profile['next_action'] }}
                    </p>
                </x-slot:supporting>
            @endif
        </x-record-identity>

        @if ($hasPrimaryBarActions || $hasDangerBarActions)
            <div
                aria-label="Account actions"
                class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200/80 bg-gray-50/80 px-4 py-3 sm:px-6 dark:border-white/10 dark:bg-white/[0.02]"
            >
                <div class="flex flex-wrap items-center gap-2">
                    @if ($this->resendInvitationAction->isVisible())
                        {{ $this->resendInvitationAction }}
                    @endif
                    @if ($this->correctPendingEmailAction->isVisible())
                        {{ $this->correctPendingEmailAction }}
                    @endif
                    @if ($this->resendVerificationAction->isVisible())
                        {{ $this->resendVerificationAction }}
                    @endif
                    @if ($this->reactivateAction->isVisible())
                        {{ $this->reactivateAction }}
                    @endif
                    @if ($this->editRoleAndDetailsAction->isVisible())
                        {{ $this->editRoleAndDetailsAction }}
                    @endif
                    @if ($this->changeSignInEmailAction->isVisible())
                        {{ $this->changeSignInEmailAction }}
                    @endif
                    @if ($this->sendRecoveryLinkAction->isVisible())
                        {{ $this->sendRecoveryLinkAction }}
                    @endif
                </div>

                @if ($hasDangerBarActions)
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($this->resetMfaAction->isVisible())
                            {{ $this->resetMfaAction }}
                        @endif
                        @if ($this->cancelInvitationAction->isVisible())
                            {{ $this->cancelInvitationAction }}
                        @endif
                        @if ($this->disableAction->isVisible())
                            {{ $this->disableAction }}
                        @endif
                    </div>
                @endif
            </div>
        @endif

        <dl class="tala-kpi-tray" aria-label="Account highlights">
            @foreach ($profile['kpi_tray'] as $cell)
                <div class="tala-kpi-tray__cell">
                    <dt class="tala-kpi-tray__label">{{ $cell['label'] }}</dt>
                    <dd class="m-0 grid gap-0.5">
                        <p @class([
                            'tala-kpi-tray__value',
                            'tala-kpi-tray__value--'.$cell['tone'] => ($cell['tone'] ?? 'default') !== 'default',
                        ])>
                            {{ $cell['value'] }}
                        </p>
                        <p class="tala-kpi-tray__note">{{ $cell['note'] }}</p>
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2" style="gap: var(--tala-card-gap);">
        <section
            aria-labelledby="user-access-context-heading"
            class="tala-task-surface p-5 sm:p-6 space-y-4"
        >
            <div class="flex items-start gap-3">
                <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200/90 bg-gray-50 text-emerald-700 dark:border-white/10 dark:bg-white/[0.04] dark:text-emerald-400">
                    <x-filament::icon icon="heroicon-o-identification" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <h2 id="user-access-context-heading" class="text-base font-semibold text-gray-950 dark:text-white">
                        Access & Institutional Context
                    </h2>
                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
                        Assigned roles, authorized workspaces, and linked institutional records.
                    </p>
                </div>
            </div>

            <dl class="divide-y divide-gray-200/80 text-sm dark:divide-white/10">
                <div class="py-3 first:pt-0 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                    <dt class="font-medium text-gray-600 dark:text-gray-400">Role</dt>
                    <dd class="min-w-0">
                        @if ($profile['roles'] !== [])
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($profile['roles'] as $role)
                                    <x-filament::badge :color="$role['color']" :icon="$role['icon'] ?? null">
                                        {{ $role['label'] }}
                                    </x-filament::badge>
                                @endforeach
                            </div>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">None assigned</span>
                        @endif
                    </dd>
                </div>

                <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                    <dt class="font-medium text-gray-600 dark:text-gray-400">Authorized workspaces</dt>
                    <dd class="min-w-0 text-gray-950 dark:text-white">
                        {{ $profile['workspaces'] }}
                    </dd>
                </div>

                <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                    <dt class="font-medium text-gray-600 dark:text-gray-400">Sign-in eligibility</dt>
                    <dd class="min-w-0">
                        <x-filament::badge :color="$profile['sign_in_allowed'] ? 'success' : 'danger'">
                            {{ $profile['sign_in_allowed'] ? 'Allowed' : 'Blocked' }}
                        </x-filament::badge>
                    </dd>
                </div>

                @if ($profile['show_staff_details'])
                    <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Staff ID</dt>
                        <dd class="min-w-0 font-medium text-gray-950 tabular-nums dark:text-white">
                            {{ $profile['staff_identifier'] }}
                        </dd>
                    </div>

                    @if (filled($profile['staff_recorded_name']))
                        <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                            <dt class="font-medium text-gray-600 dark:text-gray-400">Recorded staff name</dt>
                            <dd class="min-w-0 text-gray-950 dark:text-white">
                                {{ $profile['staff_recorded_name'] }}
                            </dd>
                        </div>
                    @endif

                    <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Latest staff invitation</dt>
                        <dd class="min-w-0 text-gray-950 dark:text-white">
                            {{ $profile['latest_invitation_status'] }}
                        </dd>
                    </div>
                @endif

                @if ($profile['show_staff_details'] || $profile['pending_email_change'] !== 'None')
                    <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Pending email change</dt>
                        <dd class="min-w-0 text-gray-950 dark:text-white">
                            {{ $profile['pending_email_change'] }}
                        </dd>
                    </div>
                @endif

                @if ($profile['show_student_record'])
                    <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Linked student record</dt>
                        <dd class="min-w-0">
                            @if ($profile['student_record'] !== null)
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <code class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-gray-900 tabular-nums dark:border-white/10 dark:bg-white/[0.05] dark:text-white">
                                        {{ $profile['student_record']['number'] }}
                                    </code>
                                    <x-filament::badge :color="$profile['student_record']['lifecycle_color']">
                                        {{ $profile['student_record']['lifecycle'] }}
                                    </x-filament::badge>
                                    @if (filled($profile['student_record']['program']))
                                        <x-filament::badge color="gray">
                                            {{ $profile['student_record']['program'] }}
                                        </x-filament::badge>
                                    @endif
                                    <span class="text-xs text-gray-600 tabular-nums dark:text-gray-400">
                                        {{ $profile['student_record']['enrollments_label'] }}
                                    </span>
                                </div>
                            @else
                                <span class="text-gray-500 dark:text-gray-400">Not linked yet</span>
                            @endif
                        </dd>
                    </div>
                @endif

                @if ($profile['show_applicant_record'])
                    <div class="py-3 last:pb-0 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Linked applicant record</dt>
                        <dd class="min-w-0">
                            @if ($profile['applicant_record'] !== null)
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <code class="rounded-md border border-gray-200 bg-gray-50 px-2 py-0.5 text-xs font-semibold text-gray-900 tabular-nums dark:border-white/10 dark:bg-white/[0.05] dark:text-white">
                                        {{ $profile['applicant_record']['reference'] }}
                                    </code>
                                    <x-filament::badge color="gray">
                                        {{ $profile['applicant_record']['state'] }}
                                    </x-filament::badge>
                                    <span class="text-xs text-gray-600 tabular-nums dark:text-gray-400">
                                        {{ $profile['applicant_record']['count_label'] }}
                                    </span>
                                </div>
                            @else
                                <span class="text-gray-500 dark:text-gray-400">None recorded</span>
                            @endif
                        </dd>
                    </div>
                @endif
            </dl>
        </section>

        <section
            aria-labelledby="user-security-signin-heading"
            class="tala-task-surface p-5 sm:p-6 space-y-4"
        >
            <div class="flex items-start gap-3">
                <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200/90 bg-gray-50 text-emerald-700 dark:border-white/10 dark:bg-white/[0.04] dark:text-emerald-400">
                    <x-filament::icon icon="heroicon-o-shield-check" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <h2 id="user-security-signin-heading" class="text-base font-semibold text-gray-950 dark:text-white">
                        Security & Sign-In
                    </h2>
                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
                        Verification, MFA readiness, sign-in activity, and lifecycle status.
                    </p>
                </div>
            </div>

            <dl class="divide-y divide-gray-200/80 text-sm dark:divide-white/10">
                <div class="py-3 first:pt-0 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                    <dt class="font-medium text-gray-600 dark:text-gray-400">Email verification</dt>
                    <dd class="min-w-0 text-gray-950 tabular-nums dark:text-white">
                        {{ $profile['email_verified_at_display'] }}
                    </dd>
                </div>

                <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                    <dt class="font-medium text-gray-600 dark:text-gray-400">Sign-in credentials</dt>
                    <dd class="min-w-0 space-y-0.5">
                        <p class="font-medium text-gray-950 dark:text-white">
                            {{ $profile['credential_state'] }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            IT Staff sends a reset link instead of setting passwords.
                        </p>
                    </dd>
                </div>

                <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                    <dt class="font-medium text-gray-600 dark:text-gray-400">MFA status</dt>
                    <dd class="min-w-0 flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$profile['mfa_color']">
                            {{ $profile['mfa_label'] }}
                        </x-filament::badge>
                        @if ($profile['is_staff_capable'])
                            <span class="text-xs text-gray-500 tabular-nums dark:text-gray-400">
                                {{ $profile['mfa_enrolled_at_display'] }}
                            </span>
                        @endif
                    </dd>
                </div>

                @if ($profile['is_staff_capable'])
                    <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Recovery codes confirmed</dt>
                        <dd class="min-w-0 text-gray-950 tabular-nums dark:text-white">
                            {{ $profile['recovery_codes_confirmed_display'] }}
                        </dd>
                    </div>
                @endif

                <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                    <dt class="font-medium text-gray-600 dark:text-gray-400">Last sign-in</dt>
                    <dd class="min-w-0 text-gray-950 tabular-nums dark:text-white">
                        {{ $profile['last_sign_in_display'] }}
                    </dd>
                </div>

                <div class="py-3 last:pb-0 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                    <dt class="font-medium text-gray-600 dark:text-gray-400">Account created</dt>
                    <dd class="min-w-0 text-gray-950 tabular-nums dark:text-white">
                        {{ $profile['created_at_display'] }}
                    </dd>
                </div>

                @if ($profile['is_disabled'])
                    <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Disabled at</dt>
                        <dd class="min-w-0 text-gray-950 tabular-nums dark:text-white">
                            {{ $profile['disabled_at_display'] }}
                        </dd>
                    </div>

                    <div class="py-3 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Disablement reason</dt>
                        <dd class="min-w-0 text-gray-950 dark:text-white">
                            {{ $profile['disabled_reason'] }}
                        </dd>
                    </div>

                    <div class="py-3 last:pb-0 grid grid-cols-1 gap-1 sm:grid-cols-[11.5rem_minmax(0,1fr)] sm:gap-4">
                        <dt class="font-medium text-gray-600 dark:text-gray-400">Approval reference</dt>
                        <dd class="min-w-0 text-gray-950 dark:text-white">
                            {{ $profile['disabled_authority'] }}
                        </dd>
                    </div>
                @endif
            </dl>
        </section>
    </div>

    <section
        aria-labelledby="user-recent-activity-heading"
        class="tala-task-surface p-5 sm:p-6 space-y-4"
    >
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200/90 bg-gray-50 text-emerald-700 dark:border-white/10 dark:bg-white/[0.04] dark:text-emerald-400">
                    <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                </span>
                <div class="min-w-0">
                    <h2 id="user-recent-activity-heading" class="text-base font-semibold text-gray-950 dark:text-white">
                        Recent Activity
                    </h2>
                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
                        Latest 5 allowlisted account and security events.
                    </p>
                </div>
            </div>
            <a
                href="{{ $profile['full_activity_url'] }}"
                class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 hover:text-emerald-800 dark:text-emerald-400 dark:hover:text-emerald-300"
            >
                <span>View full activity log</span>
                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4 shrink-0" />
            </a>
        </div>

        @include('filament.admin.users.recent-audit-history', ['events' => $profile['recent_events']])
    </section>
</div>
