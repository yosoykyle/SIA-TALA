<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Filament\Pages\GovernanceAudit;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\OperationalEvent;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\DisplayDateTime;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.admin.users.profile'),
        ]);
    }

    /**
     * Build the read-only, secret-free account profile projection for ViewUser.
     *
     * @return array{
     *     id: int,
     *     display_name: string,
     *     initials: string,
     *     email: string,
     *     identity_pill: string,
     *     status_label: string,
     *     status_color: string,
     *     mfa_label: string,
     *     mfa_color: string,
     *     email_verified_label: string,
     *     email_verified_color: string,
     *     email_verified_at_display: string,
     *     next_action: string,
     *     kpi_tray: list<array{label: string, value: string, note: string, tone: string}>,
     *     roles: list<array{name: string, label: string, color: string, icon: ?string}>,
     *     workspaces: string,
     *     sign_in_allowed: bool,
     *     is_staff_capable: bool,
     *     show_staff_details: bool,
     *     staff_identifier: string,
     *     staff_recorded_name: ?string,
     *     latest_invitation_status: string,
     *     pending_email_change: string,
     *     show_student_record: bool,
     *     student_record_summary: string,
     *     student_record: ?array{number: string, lifecycle: string, lifecycle_color: string, program: ?string, enrollments_label: string},
     *     show_applicant_record: bool,
     *     applicant_record_summary: string,
     *     applicant_record: ?array{reference: string, state: string, count_label: string},
     *     credential_state: string,
     *     mfa_enrolled_at_display: string,
     *     recovery_codes_confirmed_display: string,
     *     last_sign_in_display: string,
     *     created_at_display: string,
     *     is_disabled: bool,
     *     disabled_at_display: string,
     *     disabled_reason: string,
     *     disabled_authority: string,
     *     recent_events: list<array{event: string, occurred_at: string, actor: string, source: string, status: string}>,
     *     full_activity_url: string
     * }
     */
    public static function profileData(User $record): array
    {
        $record->loadMissing([
            'roles',
            'staffAccessProfile',
            'studentProfile.program',
            'currentAdmissionApplication',
        ]);

        $identity = UsersTable::institutionalIdentity($record);
        $isStaffCapable = $record->isStaffCapable();
        $staffProfile = $record->staffAccessProfile;
        $showStaffDetails = $isStaffCapable || $staffProfile !== null;

        $roles = $record->roles
            ->pluck('name')
            ->values()
            ->map(fn (string $role): array => [
                'name' => $role,
                'label' => UsersTable::roleLabel($role),
                'color' => UsersTable::roleColor($role),
                'icon' => UsersTable::roleIcon($role),
            ])
            ->all();

        $workspaces = [];

        if ($isStaffCapable) {
            $workspaces[] = 'Staff Workspace';
        }

        if ($record->hasAssignedRole('student')) {
            $workspaces[] = 'Student Hub';
        }

        if ($record->hasAssignedRole('applicant')) {
            $workspaces[] = 'Applicant Portal';
        }

        $recordedStaffName = null;

        if ($staffProfile !== null) {
            $parts = array_filter([
                $staffProfile->first_name,
                $staffProfile->middle_name,
                $staffProfile->last_name,
                $staffProfile->suffix,
            ], filled(...));

            $recordedStaffName = $parts !== [] ? implode(' ', $parts) : null;
        }

        $studentProfile = $record->studentProfile;
        $showStudentRecord = $studentProfile instanceof StudentProfile || $record->hasAssignedRole('student');
        $enrollmentsCount = $studentProfile instanceof StudentProfile ? $studentProfile->enrollments()->count() : 0;
        $studentRecord = self::studentRecordChips($studentProfile, $enrollmentsCount);
        $studentRecordSummary = self::studentRecordSummary($studentProfile, $enrollmentsCount);

        $applicationCount = $record->admissionApplications()->canonical()->count();
        $showApplicantRecord = $applicationCount > 0 || $record->hasAssignedRole('applicant');
        $applicantRecord = self::applicantRecordChips($record, $applicationCount);
        $applicantRecordSummary = self::applicantRecordSummary($record, $applicationCount);

        $statusLabel = User::statusLabel($record->status);
        $statusColor = User::statusColor($record->status);
        $mfaLabel = UsersTable::mfaStatusLabel($record);
        $mfaColor = UsersTable::mfaStatusColor($record);
        $emailVerifiedLabel = $record->email_verified_at !== null ? 'Email verified' : 'Email pending';
        $emailVerifiedAtDisplay = DisplayDateTime::format($record->email_verified_at, 'M j, Y g:i A', 'Pending verification');
        $mfaEnrolledAtDisplay = DisplayDateTime::format(
            $record->two_factor_confirmed_at,
            'M j, Y g:i A',
            $isStaffCapable ? 'Not enrolled' : 'Not required',
        );
        $recentEvents = self::recentAuditEvents($record);
        $securityEventsCount = self::securityEventsCount($record);

        $kpiTray = self::buildKpiTray(
            record: $record,
            isStaffCapable: $isStaffCapable,
            roles: $roles,
            statusLabel: $statusLabel,
            statusColor: $statusColor,
            mfaLabel: $mfaLabel,
            mfaColor: $mfaColor,
            mfaEnrolledAtDisplay: $mfaEnrolledAtDisplay,
            emailVerifiedLabel: $emailVerifiedLabel,
            emailVerifiedAtDisplay: $emailVerifiedAtDisplay,
            studentProfile: $studentProfile,
            enrollmentsCount: $enrollmentsCount,
            applicationCount: $applicationCount,
            applicantRecord: $applicantRecord,
            recentEvents: $recentEvents,
            securityEventsCount: $securityEventsCount,
        );

        return [
            'id' => (int) $record->getKey(),
            'display_name' => UsersTable::displayName($record),
            'initials' => UsersTable::initials($record),
            'email' => (string) $record->email,
            'identity_pill' => $identity['pill'],
            'status_label' => $statusLabel,
            'status_color' => $statusColor,
            'mfa_label' => $mfaLabel,
            'mfa_color' => $mfaColor,
            'email_verified_label' => $emailVerifiedLabel,
            'email_verified_color' => $record->email_verified_at !== null ? 'success' : 'warning',
            'email_verified_at_display' => $emailVerifiedAtDisplay,
            'next_action' => self::nextActionSummary($record),
            'kpi_tray' => $kpiTray,
            'roles' => $roles,
            'workspaces' => $workspaces !== [] ? implode(' · ', $workspaces) : 'No workspace access',
            'sign_in_allowed' => $record->canAuthenticate(),
            'is_staff_capable' => $isStaffCapable,
            'show_staff_details' => $showStaffDetails,
            'staff_identifier' => filled($staffProfile?->staff_identifier) ? (string) $staffProfile->staff_identifier : 'Not assigned',
            'staff_recorded_name' => $recordedStaffName,
            'latest_invitation_status' => self::latestInvitationSummary($record),
            'pending_email_change' => self::pendingEmailChangeSummary($record),
            'show_student_record' => $showStudentRecord,
            'student_record_summary' => $studentRecordSummary,
            'student_record' => $studentRecord,
            'show_applicant_record' => $showApplicantRecord,
            'applicant_record_summary' => $applicantRecordSummary,
            'applicant_record' => $applicantRecord,
            'credential_state' => $record->password !== null
                ? 'Set by account owner'
                : 'Waiting for account owner activation',
            'mfa_enrolled_at_display' => $mfaEnrolledAtDisplay,
            'recovery_codes_confirmed_display' => DisplayDateTime::format(
                $record->two_factor_recovery_codes_acknowledged_at,
                'M j, Y g:i A',
                $isStaffCapable ? 'Not recorded' : 'Not required',
            ),
            'last_sign_in_display' => DisplayDateTime::format($record->last_successful_sign_in_at, 'M j, Y g:i A', 'Never'),
            'created_at_display' => DisplayDateTime::format($record->created_at, 'M j, Y g:i A', 'Not recorded'),
            'is_disabled' => $record->status === User::StatusDisabled,
            'disabled_at_display' => DisplayDateTime::format($record->disabled_at, 'M j, Y g:i A', 'Not recorded'),
            'disabled_reason' => filled($record->disabled_reason) ? (string) $record->disabled_reason : 'Not recorded',
            'disabled_authority' => filled($record->disabled_authority) ? (string) $record->disabled_authority : 'Not recorded',
            'recent_events' => $recentEvents,
            'full_activity_url' => GovernanceAudit::getUrl([
                'actor' => (string) $record->getKey(),
            ]),
        ];
    }

    /**
     * @param  list<array{name: string, label: string, color: string, icon: ?string}>  $roles
     * @param  ?array{reference: string, state: string, count_label: string}  $applicantRecord
     * @param  list<array{event: string, occurred_at: string, actor: string, source: string, status: string}>  $recentEvents
     * @return list<array{label: string, value: string, note: string, tone: string}>
     */
    private static function buildKpiTray(
        User $record,
        bool $isStaffCapable,
        array $roles,
        string $statusLabel,
        string $statusColor,
        string $mfaLabel,
        string $mfaColor,
        string $mfaEnrolledAtDisplay,
        string $emailVerifiedLabel,
        string $emailVerifiedAtDisplay,
        ?StudentProfile $studentProfile,
        int $enrollmentsCount,
        int $applicationCount,
        ?array $applicantRecord,
        array $recentEvents,
        int $securityEventsCount,
    ): array {
        $lastSignInDate = DisplayDateTime::format($record->last_successful_sign_in_at, 'M j, Y', 'Never');
        $lastSignInNote = $record->last_successful_sign_in_at !== null
            ? DisplayDateTime::format($record->last_successful_sign_in_at, 'g:i A').' · Asia/Manila'
            : 'No sign-in recorded';
        $latestEventNote = $recentEvents !== []
            ? 'Latest: '.$recentEvents[0]['event']
            : 'No recent events recorded';

        if ($isStaffCapable) {
            $roleCount = count($roles);

            return [
                [
                    'label' => 'Assigned roles',
                    'value' => number_format($roleCount),
                    'note' => $roleCount > 1 ? 'Multi-role account' : ($roles[0]['label'] ?? 'Unassigned'),
                    'tone' => 'default',
                ],
                [
                    'label' => 'MFA status',
                    'value' => $mfaLabel,
                    'note' => filled($record->two_factor_confirmed_at)
                        ? 'Enrolled '.$mfaEnrolledAtDisplay
                        : 'Required at next sign-in',
                    'tone' => $mfaColor === 'success' ? 'success' : 'warning',
                ],
                [
                    'label' => 'Last sign-in',
                    'value' => $lastSignInDate,
                    'note' => $lastSignInNote,
                    'tone' => 'default',
                ],
                [
                    'label' => 'Security events',
                    'value' => number_format($securityEventsCount),
                    'note' => $latestEventNote,
                    'tone' => 'default',
                ],
            ];
        }

        if ($record->hasAssignedRole('student') || $studentProfile instanceof StudentProfile) {
            return [
                [
                    'label' => 'Enrollments',
                    'value' => number_format($enrollmentsCount),
                    'note' => $studentProfile?->program?->code
                        ?? $studentProfile?->program?->name
                        ?? 'No program recorded',
                    'tone' => 'default',
                ],
                [
                    'label' => 'Account status',
                    'value' => $statusLabel,
                    'note' => $emailVerifiedLabel,
                    'tone' => in_array($statusColor, ['success', 'warning', 'danger'], true) ? $statusColor : 'default',
                ],
                [
                    'label' => 'Last sign-in',
                    'value' => $lastSignInDate,
                    'note' => $lastSignInNote,
                    'tone' => 'default',
                ],
                [
                    'label' => 'Security events',
                    'value' => number_format($securityEventsCount),
                    'note' => $latestEventNote,
                    'tone' => 'default',
                ],
            ];
        }

        return [
            [
                'label' => 'Applications',
                'value' => number_format($applicationCount),
                'note' => $applicantRecord !== null ? 'Latest: '.$applicantRecord['state'] : 'No applications yet',
                'tone' => 'default',
            ],
            [
                'label' => 'Email status',
                'value' => $record->email_verified_at !== null ? 'Verified' : 'Pending',
                'note' => $emailVerifiedAtDisplay,
                'tone' => $record->email_verified_at !== null ? 'success' : 'warning',
            ],
            [
                'label' => 'Last sign-in',
                'value' => $lastSignInDate,
                'note' => $lastSignInNote,
                'tone' => 'default',
            ],
            [
                'label' => 'Account created',
                'value' => DisplayDateTime::format($record->created_at, 'M j, Y', 'Not recorded'),
                'note' => $record->created_at !== null
                    ? DisplayDateTime::format($record->created_at, 'g:i A').' · Asia/Manila'
                    : 'Not recorded',
                'tone' => 'default',
            ],
        ];
    }

    /**
     * @return ?array{number: string, lifecycle: string, lifecycle_color: string, program: ?string, enrollments_label: string}
     */
    private static function studentRecordChips(?StudentProfile $profile, int $enrollmentsCount): ?array
    {
        if (! $profile instanceof StudentProfile) {
            return null;
        }

        return [
            'number' => filled($profile->student_number) ? (string) $profile->student_number : 'Student #'.$profile->getKey(),
            'lifecycle' => StudentProfile::lifecycleStatusLabel($profile->lifecycle_status),
            'lifecycle_color' => $profile->lifecycle_status === StudentProfile::LifecycleActive ? 'success' : 'gray',
            'program' => $profile->program?->code ?? $profile->program?->name,
            'enrollments_label' => number_format($enrollmentsCount).' '.Str::plural('enrollment', $enrollmentsCount),
        ];
    }

    private static function studentRecordSummary(?StudentProfile $profile, int $enrollmentsCount): string
    {
        if (! $profile instanceof StudentProfile) {
            return 'Not linked yet';
        }

        $parts = array_filter([
            filled($profile->student_number) ? $profile->student_number : 'Student #'.$profile->getKey(),
            StudentProfile::lifecycleStatusLabel($profile->lifecycle_status),
            $profile->program?->code ?? $profile->program?->name,
        ], filled(...));

        $parts[] = $enrollmentsCount.' '.Str::plural('enrollment', $enrollmentsCount);

        return implode(' · ', $parts);
    }

    /**
     * @return ?array{reference: string, state: string, count_label: string}
     */
    private static function applicantRecordChips(User $record, int $count): ?array
    {
        if ($count === 0) {
            return null;
        }

        $latest = $record->currentAdmissionApplication
            ?? $record->admissionApplications()->canonical()->latest('id')->first();

        $reference = filled($latest?->application_reference)
            ? (string) $latest->application_reference
            : 'Application #'.$latest?->getKey();
        $state = filled($latest?->application_state)
            ? Str::headline((string) $latest->application_state)
            : 'Recorded';

        return [
            'reference' => $reference,
            'state' => $state,
            'count_label' => number_format($count).' '.Str::plural('application', $count),
        ];
    }

    private static function applicantRecordSummary(User $record, int $count): string
    {
        $chips = self::applicantRecordChips($record, $count);

        if ($chips === null) {
            return 'None recorded';
        }

        return "{$chips['reference']} ({$chips['state']}) · {$chips['count_label']}";
    }

    private static function latestInvitationSummary(User $record): string
    {
        $invitation = $record->staffInvitations()->latest('id')->first();

        if ($invitation === null) {
            return 'None';
        }

        if ($invitation->accepted_at !== null) {
            return 'Accepted on '.DisplayDateTime::format($invitation->accepted_at, 'M j, Y g:i A');
        }

        if ($invitation->superseded_by_id !== null) {
            return 'Superseded by a newer invitation';
        }

        if ($invitation->expires_at?->isPast()) {
            return 'Expired on '.DisplayDateTime::format($invitation->expires_at, 'M j, Y g:i A');
        }

        return 'Pending activation (expires '.DisplayDateTime::format($invitation->expires_at, 'M j, Y g:i A').')';
    }

    private static function pendingEmailChangeSummary(User $record): string
    {
        $pending = $record->pendingEmailChanges()
            ->whereNull('verified_at')
            ->whereNull('superseded_at')
            ->latest('id')
            ->first();

        if ($pending === null) {
            return 'None';
        }

        if ($pending->expires_at?->isPast()) {
            return 'Previous request expired';
        }

        return 'Verification pending until '.DisplayDateTime::format($pending->expires_at, 'M j, Y g:i A');
    }

    private static function nextActionSummary(User $record): string
    {
        return match ($record->status) {
            User::StatusInvitationPending => 'Waiting for the staff member to accept the invitation link. Resend the invitation if the previous link expired.',
            User::StatusVerificationRequired => $record->isStaffCapable() && blank($record->two_factor_confirmed_at)
                ? 'Staff member must complete authenticator MFA setup on next sign-in.'
                : 'Waiting for the account owner to verify their email address.',
            User::StatusDisabled => 'Sign-in is blocked across all workspaces. Reactivate with documented approval when access should resume.',
            User::StatusActive => $record->isStaffCapable() && blank($record->two_factor_confirmed_at)
                ? 'Active account. Staff member will be prompted to enroll authenticator MFA.'
                : 'Active and ready for normal sign-in.',
            default => 'Review account status and assigned workspaces before taking action.',
        };
    }

    /** @return list<string> */
    private static function allowedActivityEvents(): array
    {
        return [
            'email_verified',
            'login',
            'login_failed',
            'logout',
            'mfa_challenge_succeeded',
            'mfa_disabled',
            'mfa_enabled',
            'mfa_recovery_code_used',
            'mfa_recovery_codes_regenerated',
            'password_changed',
            'password_recovery_completed',
            'sign_in_email_changed',
            'staff_access_changed',
            'staff_access_disabled',
            'staff_access_enabled',
            'staff_account_disabled',
            'staff_account_reactivated',
            'staff_email_change_requested',
            'staff_email_changed',
            'staff_identifier_updated',
            'staff_invitation_activated',
            'staff_invitation_cancelled',
            'staff_invitation_email_corrected',
            'staff_invited',
            'staff_mfa_reset',
        ];
    }

    private static function securityEventsCount(User $record): int
    {
        $activityCount = Activity::query()
            ->where(function ($query) use ($record): void {
                $query->where(function ($subject) use ($record): void {
                    $subject->where('subject_type', User::class)
                        ->where('subject_id', $record->getKey());
                })->orWhere('causer_id', $record->getKey());
            })
            ->whereIn('event', self::allowedActivityEvents())
            ->count();

        $operationalCount = OperationalEvent::query()
            ->where('user_id', $record->getKey())
            ->count();

        return $activityCount + $operationalCount;
    }

    /**
     * @return list<array{event: string, occurred_at: string, actor: string, source: string, status: string}>
     */
    public static function recentAuditEvents(User $record): array
    {
        $activities = Activity::query()
            ->with('causer')
            ->where(function ($query) use ($record): void {
                $query->where(function ($subject) use ($record): void {
                    $subject->where('subject_type', User::class)
                        ->where('subject_id', $record->getKey());
                })->orWhere('causer_id', $record->getKey());
            })
            ->whereIn('event', self::allowedActivityEvents())
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(function (Activity $activity): array {
                $eventKey = (string) ($activity->event ?? 'recorded');
                $timestamp = $activity->created_at ?? now();

                return [
                    'sort' => $timestamp->getTimestamp(),
                    'event' => self::safeEventLabel($eventKey),
                    'occurred_at' => DisplayDateTime::format($timestamp, 'M j, Y g:i A', 'Not recorded'),
                    'actor' => filled($activity->causer?->name) ? (string) $activity->causer->name : 'System',
                    'source' => 'Account audit',
                    'status' => $eventKey === 'login_failed' ? 'Attention' : 'Recorded',
                ];
            });

        $operational = OperationalEvent::query()
            ->where('user_id', $record->getKey())
            ->latest('occurred_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(function (OperationalEvent $event): array {
                $timestamp = $event->occurred_at instanceof Carbon ? $event->occurred_at : Carbon::parse((string) ($event->occurred_at ?? $event->created_at ?? now()));
                $status = strtoupper((string) $event->status);

                return [
                    'sort' => $timestamp->getTimestamp(),
                    'event' => self::safeEventLabel((string) $event->event_type),
                    'occurred_at' => DisplayDateTime::format($timestamp, 'M j, Y g:i A', 'Not recorded'),
                    'actor' => 'System',
                    'source' => 'Operational event',
                    'status' => match (true) {
                        in_array($status, ['PROCESSED', 'SUCCESS', 'COMPLETED'], true) => 'Recorded',
                        in_array($status, ['FAILED', 'REVIEW_REQUIRED', 'ERROR'], true) => 'Attention',
                        default => 'Pending',
                    },
                ];
            });

        return $activities
            ->concat($operational)
            ->sortByDesc('sort')
            ->take(5)
            ->map(fn (array $item): array => [
                'event' => $item['event'],
                'occurred_at' => $item['occurred_at'],
                'actor' => $item['actor'],
                'source' => $item['source'],
                'status' => $item['status'],
            ])
            ->values()
            ->all();
    }

    private static function safeEventLabel(string $event): string
    {
        return match ($event) {
            'password_changed' => 'Sign-in credentials updated',
            'password_recovery_completed' => 'Account recovery completed',
            'staff_invited' => 'Staff invitation created',
            'staff_invitation_activated' => 'Staff invitation accepted',
            'staff_invitation_cancelled' => 'Pending invitation cancelled',
            'staff_invitation_email_corrected' => 'Pending invitation email corrected',
            'staff_access_changed' => 'Staff roles updated',
            'staff_identifier_updated' => 'Staff ID updated',
            'staff_account_disabled', 'staff_access_disabled' => 'Account disabled',
            'staff_account_reactivated', 'staff_access_enabled' => 'Account reactivated',
            'staff_email_change_requested' => 'Staff email change requested',
            'staff_email_changed', 'sign_in_email_changed' => 'Sign-in email changed',
            'staff_mfa_reset' => 'Staff MFA reset',
            'mfa_enabled' => 'Authenticator MFA enrolled',
            'mfa_disabled' => 'Authenticator MFA removed',
            'login' => 'Signed in',
            'login_failed' => 'Sign-in attempt failed',
            'logout' => 'Signed out',
            'email_verified' => 'Email address verified',
            default => str($event)->replace('_', ' ')->headline()->replace('Password', 'Account')->toString(),
        };
    }
}
