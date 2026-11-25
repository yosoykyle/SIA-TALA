<?php

namespace App\Actions\SystemAdministration;

use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\FaqEntry;
use App\Models\FeePlan;
use App\Models\Program;
use App\Models\PublicNotice;
use App\Models\SystemSetting;
use App\Models\Term;
use App\Models\User;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class GovernanceEvidenceProjection
{
    public const InstitutionalChanges = 'institutional-changes';

    public const SystemEvents = 'system-events';

    public const OutputAccess = 'output-access';

    public const PrivacyRetention = 'privacy-retention';

    /** @return array<string, string> */
    public static function tabs(): array
    {
        return [
            self::InstitutionalChanges => 'Institutional changes',
            self::SystemEvents => 'System events',
            self::OutputAccess => 'Output access',
            self::PrivacyRetention => 'Privacy & retention',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(string $tab, int $page, int $perPage, ?string $search, array $filters): LengthAwarePaginator
    {
        $query = $this->filteredQuery($tab, $search, $filters);

        $total = (clone $query)->count();
        $rawRows = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('sort_id')
            ->forPage($page, $perPage)
            ->get();

        $users = $this->loadUsersForRows($rawRows);

        $rows = $rawRows->mapWithKeys(function (object $row) use ($users): array {
            $record = $this->formatRow($row, $users);

            return [$record['reference_id'] => $record];
        });

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * Return safe allowlisted rows matching the active tab, search, and filters for CSV export.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function exportRows(string $tab, ?string $search, array $filters, int $limit = 5000): Collection
    {
        $rawRows = $this->filteredQuery($tab, $search, $filters)
            ->orderByDesc('occurred_at')
            ->orderByDesc('sort_id')
            ->limit($limit)
            ->get();

        $users = $this->loadUsersForRows($rawRows);

        return $rawRows
            ->map(fn (object $row): array => $this->formatRow($row, $users))
            ->values();
    }

    /**
     * Find a single evidence item by reference_id directly using O(1) table query.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $tab, string $referenceId): ?array
    {
        if (! str_contains($referenceId, ':')) {
            return null;
        }

        $row = DB::query()->fromSub($this->query($tab), 'evidence')
            ->where('reference_id', $referenceId)
            ->first();

        if (! $row) {
            return null;
        }

        $users = $this->loadUsersForRows(collect([$row]));

        return $this->formatRow($row, $users);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(string $tab, ?string $search, array $filters): Builder
    {
        $query = DB::query()->fromSub($this->query($tab), 'governance_evidence');
        $actorId = $filters['actor']['value'] ?? ($filters['scoped_user_id'] ?? null);
        $source = $filters['source']['value'] ?? null;
        $type = $filters['type']['value'] ?? null;
        $from = $filters['date']['from'] ?? null;
        $until = $filters['date']['until'] ?? null;

        $query
            ->when(
                filled($actorId),
                fn (Builder $builder): Builder => $builder->where(function (Builder $scoped) use ($actorId): void {
                    $id = (int) $actorId;
                    $scoped
                        ->where('actor_id', $id)
                        ->orWhere('affected_record', 'User #'.$id);
                }),
            )
            ->when(filled($source), fn (Builder $builder): Builder => $builder->where('source', (string) $source))
            ->when(filled($type), fn (Builder $builder): Builder => $builder->where('type', (string) $type))
            ->when(filled($from), fn (Builder $builder): Builder => $builder->whereDate('occurred_at', '>=', (string) $from))
            ->when(filled($until), fn (Builder $builder): Builder => $builder->whereDate('occurred_at', '<=', (string) $until));

        $safeSearch = Str::of((string) $search)->trim()->limit(100, '')->toString();
        if ($safeSearch !== '') {
            $matchingUserIds = User::query()
                ->where(function ($userQuery) use ($safeSearch): void {
                    $userQuery
                        ->where('name', 'like', "%{$safeSearch}%")
                        ->orWhereHas('staffAccessProfile', fn ($q) => $q->where('staff_identifier', 'like', "%{$safeSearch}%"))
                        ->orWhereHas('studentProfile', fn ($q) => $q->where('student_number', 'like', "%{$safeSearch}%"))
                        ->orWhereHas('admissionApplications', fn ($q) => $q->where('application_reference', 'like', "%{$safeSearch}%"));
                })
                ->limit(100)
                ->pluck('id')
                ->all();

            $matchingUserTargets = array_map(
                static fn (int $id): string => 'User #'.$id,
                $matchingUserIds,
            );

            $query->where(function (Builder $builder) use ($safeSearch, $matchingUserIds, $matchingUserTargets): void {
                $builder
                    ->where('actor', 'like', "%{$safeSearch}%")
                    ->orWhere('actor_role', 'like', "%{$safeSearch}%")
                    ->orWhere('affected_record', 'like', "%{$safeSearch}%")
                    ->orWhere('type', 'like', "%{$safeSearch}%")
                    ->orWhere('source', 'like', "%{$safeSearch}%")
                    ->orWhere('summary', 'like', "%{$safeSearch}%");

                if ($matchingUserIds !== []) {
                    $builder
                        ->orWhereIn('actor_id', $matchingUserIds)
                        ->orWhereIn('affected_record', $matchingUserTargets);
                }
            });
        }

        return $query;
    }

    /**
     * Batch-load User models for actors and User targets to avoid N+1 queries and never expose raw User #IDs.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, User>
     */
    private function loadUsersForRows(Collection $rows): Collection
    {
        $userIds = collect();

        foreach ($rows as $row) {
            if ($row->actor_id !== null) {
                $userIds->push((int) $row->actor_id);
            }

            if (is_string($row->affected_record ?? null) && preg_match('/^User #(\d+)$/', trim($row->affected_record), $matches) === 1) {
                $userIds->push((int) $matches[1]);
            }
        }

        $ids = $userIds->unique()->filter(static fn (int $id): bool => $id > 0)->values()->all();

        if ($ids === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->with([
                'roles',
                'staffAccessProfile',
                'studentProfile',
                'currentAdmissionApplication',
                'currentApplicantIntake',
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    private function formatRow(object $row, Collection $users): array
    {
        $rawRole = (string) ($row->actor_role ?? 'System');
        $actorId = $row->actor_id === null ? null : (int) $row->actor_id;
        $actorUser = $actorId !== null ? $users->get($actorId) : null;

        if ($actorUser instanceof User) {
            $identity = $this->formatUserIdentity($actorUser, $rawRole);
            $actorName = $identity['name'];
            $actorRoleDisplay = $identity['role'];
            $actorIdentifier = $identity['identifier'];
            $actorMeta = $identity['meta'];
            $actorLabel = $identity['label'];
        } else {
            $actorName = (string) $row->actor;
            $actorRoleDisplay = self::formatRoleLabel($rawRole);
            $actorIdentifier = null;
            $actorMeta = $actorId !== null && $actorRoleDisplay !== 'System' ? $actorRoleDisplay : null;
            $actorLabel = $actorMeta !== null ? "{$actorName} ({$actorMeta})" : $actorName;
        }

        $affectedRecord = $this->resolveAffectedRecord($row->affected_record ?? null, $users);
        $rawType = (string) $row->type;
        $humanType = self::humanizeEventType($rawType);
        $summary = (string) $row->summary;

        if ($summary === '' || $summary === '__HUMANIZE_SUMMARY__') {
            $summary = $this->fallbackSummary($humanType, $affectedRecord);
        }

        return [
            'reference_id' => (string) $row->reference_id,
            'occurred_at' => (string) $row->occurred_at,
            'occurred_at_display' => self::formatOccurredAt($row->occurred_at),
            'occurred_date_display' => self::formatOccurredDate($row->occurred_at),
            'occurred_time_display' => self::formatOccurredTime($row->occurred_at),
            'actor' => $actorName,
            'actor_id' => $actorId,
            'actor_role' => $rawRole,
            'actor_role_display' => $actorRoleDisplay,
            'actor_identifier' => $actorIdentifier,
            'actor_meta' => $actorMeta,
            'actor_label' => $actorLabel,
            'affected_record' => $affectedRecord,
            'type' => $humanType,
            'source' => (string) $row->source,
            'status' => (string) $row->status,
            'summary' => $summary,
        ];
    }

    /**
     * Format a User across all 7 roles with real name, role label, and institutional ID.
     *
     * @return array{name: string, role: string, identifier: ?string, meta: string, label: string}
     */
    public function formatUserIdentity(User $user, ?string $fallbackRole = null): array
    {
        $roleNames = $user->roles->pluck('name')->values()->all();
        $primaryRoleName = $this->primaryRoleName($roleNames);
        $roleLabel = $primaryRoleName !== null
            ? self::formatRoleLabel($primaryRoleName)
            : self::formatRoleLabel($fallbackRole ?? 'Unattributed');

        $name = filled($user->name)
            ? (string) $user->name
            : ($primaryRoleName === 'applicant' ? 'Applicant account' : 'Account');

        $identifier = $this->resolveInstitutionalIdentifier($user, $primaryRoleName);
        $meta = $identifier !== null ? "{$roleLabel} · {$identifier}" : $roleLabel;

        return [
            'name' => $name,
            'role' => $roleLabel,
            'identifier' => $identifier,
            'meta' => $meta,
            'label' => "{$name} ({$meta})",
        ];
    }

    /** @param list<string> $roleNames */
    private function primaryRoleName(array $roleNames): ?string
    {
        if ($roleNames === []) {
            return null;
        }

        $priority = [
            User::StaffRoleSystemSuperAdmin,
            User::StaffRoleRegistrar,
            User::StaffRoleAccounting,
            User::StaffRoleAcademicHead,
            User::StaffRoleFaculty,
            'student',
            'applicant',
        ];

        foreach ($priority as $candidate) {
            if (in_array($candidate, $roleNames, true)) {
                return $candidate;
            }
        }

        return $roleNames[0];
    }

    private function resolveInstitutionalIdentifier(User $user, ?string $primaryRole): ?string
    {
        $isStaff = $primaryRole !== null && in_array($primaryRole, User::staffRoleNames(), true);

        if ($isStaff && filled($user->staffAccessProfile?->staff_identifier)) {
            return 'Staff ID '.$user->staffAccessProfile->staff_identifier;
        }

        if ($primaryRole === 'student' && filled($user->studentProfile?->student_number)) {
            return 'Student No. '.$user->studentProfile->student_number;
        }

        if (filled($user->staffAccessProfile?->staff_identifier)) {
            return 'Staff ID '.$user->staffAccessProfile->staff_identifier;
        }

        if (filled($user->studentProfile?->student_number)) {
            return 'Student No. '.$user->studentProfile->student_number;
        }

        $appRef = $user->currentAdmissionApplication?->application_reference
            ?? $user->currentApplicantIntake?->application_reference;

        if (filled($appRef)) {
            return 'App Ref '.$appRef;
        }

        return null;
    }

    /** @param Collection<int, User> $users */
    private function resolveAffectedRecord(mixed $value, Collection $users): string
    {
        if (is_string($value) && preg_match('/^User #(\d+)$/', trim($value), $matches) === 1) {
            $targetUser = $users->get((int) $matches[1]);

            if ($targetUser instanceof User) {
                return $this->formatUserIdentity($targetUser)['label'];
            }

            return 'User account';
        }

        return self::formatAffectedRecord($value);
    }

    private function fallbackSummary(string $humanType, string $affectedRecord): string
    {
        if ($affectedRecord !== '' && $affectedRecord !== 'None') {
            return "{$humanType} recorded for {$affectedRecord}.";
        }

        return "{$humanType} recorded.";
    }

    public static function formatOccurredAt(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Not recorded';
        }

        try {
            return DisplayDateTime::format(CarbonImmutable::parse($value), 'M j, Y g:i A', 'Not recorded');
        } catch (Throwable) {
            return 'Not recorded';
        }
    }

    public static function formatOccurredDate(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Not recorded';
        }

        try {
            return DisplayDateTime::format(CarbonImmutable::parse($value), 'M j, Y', 'Not recorded');
        } catch (Throwable) {
            return 'Not recorded';
        }
    }

    public static function formatOccurredTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return DisplayDateTime::format(CarbonImmutable::parse($value), 'g:i A', '');
        } catch (Throwable) {
            return null;
        }
    }

    public static function formatRoleLabel(string $role): string
    {
        $normalized = trim($role);

        if ($normalized === '') {
            return 'System';
        }

        return match (strtolower($normalized)) {
            'system-super-admin', 'system administrator' => 'System Administrator',
            'registrar' => 'Registrar',
            'accounting' => 'Accounting',
            'academic-head', 'academic head' => 'Academic Head',
            'faculty' => 'Faculty',
            'student' => 'Student',
            'applicant' => 'Applicant',
            'system' => 'System',
            'unattributed' => 'Unattributed',
            default => Str::headline($normalized),
        };
    }

    public static function formatAffectedRecord(mixed $value): string
    {
        if (! is_string($value)) {
            return 'None';
        }

        $trimmed = trim($value);

        if ($trimmed === '' || in_array(strtolower($trimmed), ['none', 'none recorded'], true)) {
            return 'None';
        }

        if (preg_match('/^([A-Za-z0-9_\\\\]+)\s+#(\d+)$/', $trimmed, $matches) === 1) {
            $subject = class_basename($matches[1]);
            $label = match ($subject) {
                'FaqEntry', 'faq_entry' => 'FAQ Entry',
                'PublicNotice', 'public_notice' => 'Public Notice',
                default => Str::headline($subject),
            };

            return "{$label} #{$matches[2]}";
        }

        if (str_starts_with($trimmed, 'Integration: ')) {
            $integration = trim(substr($trimmed, strlen('Integration: ')));

            if ($integration === '') {
                return 'System integration';
            }

            $label = $integration === strtoupper($integration)
                ? Str::of($integration)->lower()->headline()->toString()
                : Str::headline($integration);

            return $label.' integration';
        }

        return $trimmed;
    }

    public static function humanizeEventType(string $type): string
    {
        $trimmed = trim($type);

        if ($trimmed === '') {
            return 'Recorded';
        }

        if ($trimmed === strtoupper($trimmed) && preg_match('/[A-Z]/', $trimmed) === 1) {
            $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $trimmed));

            $knownCompact = match ($compact) {
                'TOR' => 'TOR',
                'COR' => 'COR',
                'INITIALRELEASE' => 'Initial release',
                'PENDINGREPLACEMENT' => 'Pending replacement',
                'INCRESOLUTION' => 'INC resolution',
                'POSTEDCORRECTION' => 'Posted correction',
                'GRADECORRECTION' => 'Grade correction',
                'LIFECYCLEOUTCOME' => 'Lifecycle outcome',
                'SUBJECTDROP' => 'Subject drop',
                'WITHDRAWAL' => 'Withdrawal',
                'LEAVEOFABSENCE' => 'Leave of absence',
                'TRANSFEROUT' => 'Transfer out',
                'REACTIVATION' => 'Reactivation',
                'INACTIVATION' => 'Inactivation',
                'PROGRAMSHIFT' => 'Program shift',
                'COMPLETION' => 'Completion',
                default => null,
            };

            if ($knownCompact !== null) {
                return $knownCompact;
            }

            return Str::of($trimmed)->lower()->headline()->toString();
        }

        return Str::headline($trimmed);
    }

    /** @return array<int, string> */
    public function actorOptions(): array
    {
        return User::query()
            ->whereHas('roles')
            ->with([
                'roles',
                'staffAccessProfile',
                'studentProfile',
                'currentAdmissionApplication',
                'currentApplicantIntake',
            ])
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => $this->formatUserIdentity($user)['label'],
            ])
            ->all();
    }

    /** @return array<string, string> */
    public function sourceOptions(string $tab): array
    {
        $sources = DB::query()
            ->fromSub($this->query($tab), 'evidence')
            ->whereNotNull('source')
            ->distinct()
            ->orderBy('source')
            ->pluck('source');

        return $sources
            ->filter(fn (mixed $source): bool => is_string($source) && $source !== '')
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $source): array => [$source => $source])
            ->all();
    }

    /** @return array<string, string> */
    public function typeOptions(string $tab): array
    {
        $types = DB::query()
            ->fromSub($this->query($tab), 'evidence')
            ->whereNotNull('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        return $types
            ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $type): array => [$type => self::humanizeEventType($type)])
            ->all();
    }

    public function query(string $tab): Builder
    {
        return match ($tab) {
            self::InstitutionalChanges => $this->institutionalChangesQuery(),
            self::SystemEvents => $this->systemEventsQuery(),
            self::OutputAccess => $this->outputAccessQuery(),
            default => DB::query()->fromSub(DB::query()->selectRaw("1 AS sort_id, 'none' AS reference_id, NULL AS occurred_at, NULL AS actor_id, 'System' AS actor, 'System' AS actor_role, 'None' AS affected_record, 'none' AS type, 'None' AS source, 'Recorded' AS status, 'No evidence.' AS summary")->whereRaw('1 = 0'), 'evidence'),
        };
    }

    public static function actorRoleSql(string $userAlias): string
    {
        return '(SELECT CASE roles.name '
            ."WHEN 'system-super-admin' THEN 'System Administrator' "
            ."WHEN 'registrar' THEN 'Registrar' "
            ."WHEN 'accounting' THEN 'Accounting' "
            ."WHEN 'academic-head' THEN 'Academic Head' "
            ."WHEN 'faculty' THEN 'Faculty' "
            ."WHEN 'student' THEN 'Student' "
            ."WHEN 'applicant' THEN 'Applicant' "
            .'ELSE roles.name END '
            .'FROM model_has_roles '
            .'JOIN roles ON roles.id = model_has_roles.role_id '
            ."WHERE model_has_roles.model_id = {$userAlias}.id "
            ."AND model_has_roles.model_type = 'App\\\\Models\\\\User' "
            .'ORDER BY CASE roles.name '
            ."WHEN 'system-super-admin' THEN 1 "
            ."WHEN 'registrar' THEN 2 "
            ."WHEN 'accounting' THEN 3 "
            ."WHEN 'academic-head' THEN 4 "
            ."WHEN 'faculty' THEN 5 "
            ."WHEN 'student' THEN 6 "
            ."WHEN 'applicant' THEN 7 "
            .'ELSE 8 END ASC LIMIT 1)';
    }

    public static function actorSql(string $userAlias, string $actorIdColumn): string
    {
        return "CASE WHEN {$userAlias}.name IS NOT NULL AND {$userAlias}.name != '' THEN {$userAlias}.name WHEN {$actorIdColumn} IS NOT NULL THEN 'Unattributed' ELSE 'System' END";
    }

    public static function actorRoleResolutionSql(string $userAlias, string $actorIdColumn): string
    {
        return "CASE WHEN {$actorIdColumn} IS NULL THEN 'System' ELSE COALESCE(".self::actorRoleSql($userAlias).", 'Unattributed') END";
    }

    private function institutionalChangesQuery(): Builder
    {
        $excludedEvents = array_merge(self::authenticationEventsList(), self::readOnlyAccessEvents());

        $activity = DB::table('activity_log as activity')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'activity.causer_id')
            ->where(function (Builder $query): void {
                $query->whereNull('activity.log_name')
                    ->orWhere('activity.log_name', '!=', 'authentication');
            })
            ->whereNotNull('activity.event')
            ->where('activity.event', '!=', '')
            ->whereNotIn('activity.event', $excludedEvents)
            ->where(function (Builder $query): void {
                $query->whereNotIn('activity.event', self::institutionalGenericEvents())
                    ->orWhere(function (Builder $genericQuery): void {
                        $genericQuery->whereIn('activity.event', self::institutionalGenericEvents())
                            ->whereIn('activity.subject_type', self::supportedInstitutionalGenericSubjects());
                    });
            })
            ->selectRaw("activity.id AS sort_id, CONCAT('activity:', activity.id) AS reference_id, activity.created_at AS occurred_at, activity.causer_id AS actor_id, ".self::actorSql('actor_user', 'activity.causer_id').' AS actor, '.self::actorRoleResolutionSql('actor_user', 'activity.causer_id')." AS actor_role, CASE WHEN activity.subject_type IS NOT NULL AND activity.subject_id IS NOT NULL THEN CONCAT(REPLACE(activity.subject_type, 'App\\\\Models\\\\', ''), ' #', activity.subject_id) ELSE 'Institutional configuration' END AS affected_record, activity.event AS type, 'Institutional change' AS source, 'Recorded' AS status, CASE WHEN activity.event = 'staff_access_changed' THEN 'Staff access privileges modified.' WHEN activity.event IN ('staff_access_disabled', 'staff_account_disabled') THEN 'Staff access disabled.' WHEN activity.event IN ('staff_access_enabled', 'staff_account_reactivated') THEN 'Staff access enabled.' WHEN activity.event = 'staff_invited' THEN 'Staff invitation created.' WHEN activity.event = 'staff_invitation_activated' THEN 'Staff invitation accepted and activated.' WHEN activity.event = 'hold_created' THEN 'Student hold placed.' WHEN activity.event = 'hold_waived' THEN 'Student hold waived.' WHEN activity.event = 'hold_resolved' THEN 'Student hold resolved.' WHEN activity.event = 'hold_expired' THEN 'Student hold expired.' WHEN activity.event = 'failed_jobs_retried' THEN 'Failed queue jobs re-queued by administrator.' WHEN activity.event = 'published' THEN 'Public content published.' WHEN activity.event = 'unpublished' THEN 'Public content unpublished.' WHEN activity.event = 'reordered' THEN 'Public content display order updated.' WHEN activity.event = 'created' THEN 'Institutional record created.' WHEN activity.event = 'updated' THEN 'Institutional record updated.' WHEN activity.event = 'deleted' THEN 'Institutional record deleted.' ELSE '__HUMANIZE_SUMMARY__' END AS summary");

        $admissionCycles = DB::table('admission_cycle_events as ace')
            ->leftJoin('users as u', 'u.id', '=', 'ace.actor_id')
            ->whereIn('ace.event_type', self::supportedAdmissionCycleEvents())
            ->selectRaw("ace.id AS sort_id, CONCAT('admission_cycle:', ace.id) AS reference_id, ace.occurred_at AS occurred_at, ace.actor_id AS actor_id, ".self::actorSql('u', 'ace.actor_id').' AS actor, '.self::actorRoleResolutionSql('u', 'ace.actor_id')." AS actor_role, CONCAT('AdmissionCycle #', ace.admission_cycle_id) AS affected_record, ace.event_type AS type, 'Admission cycle' AS source, 'Recorded' AS status, CASE WHEN LOWER(ace.event_type) IN ('published', 'typepublished') THEN 'Admission cycle published.' WHEN LOWER(ace.event_type) IN ('dateschanged', 'dates_changed') THEN 'Admission cycle dates modified.' WHEN LOWER(ace.event_type) IN ('cancelled', 'typecancelled') THEN 'Admission cycle cancelled.' ELSE 'Admission cycle change recorded.' END AS summary");

        $admissionApps = DB::table('admission_application_events as aae')
            ->leftJoin('users as u', 'u.id', '=', 'aae.actor_id')
            ->whereIn('aae.event_type', self::supportedAdmissionApplicationEvents())
            ->selectRaw("aae.id AS sort_id, CONCAT('admission_app:', aae.id) AS reference_id, aae.occurred_at AS occurred_at, aae.actor_id AS actor_id, ".self::actorSql('u', 'aae.actor_id').' AS actor, '.self::actorRoleResolutionSql('u', 'aae.actor_id')." AS actor_role, CONCAT('AdmissionApplication #', aae.admission_application_id) AS affected_record, aae.event_type AS type, 'Admission application' AS source, 'Recorded' AS status, CASE WHEN aae.event_type = 'Submitted' THEN 'Admission application submitted.' WHEN aae.event_type = 'Resubmitted' THEN 'Admission application resubmitted.' WHEN aae.event_type = 'CorrectionRequested' THEN 'Admission application correction requested.' WHEN aae.event_type = 'Withdrawn' THEN 'Admission application withdrawn.' WHEN aae.event_type = 'Reopened' THEN 'Admission application reopened.' WHEN aae.event_type = 'DecisionRecorded' THEN 'Admission decision recorded.' WHEN aae.event_type = 'CredentialResultRecorded' THEN 'Admission credential verification recorded.' WHEN aae.event_type = 'ReadinessBecameTrue' THEN 'Admission application marked ready for enrollment.' WHEN aae.event_type = 'ReadinessBecameFalse' THEN 'Admission application marked not ready for enrollment.' ELSE 'Admission application change recorded.' END AS summary");

        $regCases = DB::table('registration_case_events as rce')
            ->leftJoin('users as u', 'u.id', '=', 'rce.actor_id')
            ->whereIn('rce.event_type', self::supportedRegistrationCaseEvents())
            ->selectRaw("rce.id AS sort_id, CONCAT('reg_case:', rce.id) AS reference_id, rce.recorded_at AS occurred_at, rce.actor_id AS actor_id, ".self::actorSql('u', 'rce.actor_id').' AS actor, '.self::actorRoleResolutionSql('u', 'rce.actor_id')." AS actor_role, CONCAT('Enrollment #', rce.enrollment_id) AS affected_record, rce.event_type AS type, 'Registration case' AS source, 'Recorded' AS status, CASE WHEN rce.event_type = 'CaseStarted' THEN 'Registration case initiated.' WHEN rce.event_type = 'OutcomeAssigned' THEN 'Registration case outcome assigned.' WHEN rce.event_type = 'Enrolled' THEN 'Registration case enrollment completed.' WHEN rce.event_type = 'Cancelled' THEN 'Registration case cancelled.' WHEN rce.event_type = 'Dropped' THEN 'Registration case subjects dropped.' WHEN rce.event_type = 'Reinstated' THEN 'Registration case reinstated.' ELSE 'Registration case transition recorded.' END AS summary");

        $timetables = DB::table('timetable_revisions as tr')
            ->leftJoin('users as u', 'u.id', '=', 'tr.published_by')
            ->whereNotNull('tr.published_by')
            ->whereIn('tr.state', ['PUBLISHED', 'PREPARED', 'DRAFT', 'published', 'prepared', 'draft'])
            ->selectRaw("tr.id AS sort_id, CONCAT('timetable:', tr.id) AS reference_id, COALESCE(tr.published_at, tr.prepared_at, tr.created_at) AS occurred_at, tr.published_by AS actor_id, ".self::actorSql('u', 'tr.published_by').' AS actor, '.self::actorRoleResolutionSql('u', 'tr.published_by')." AS actor_role, CONCAT('TimetableRevision #', tr.id) AS affected_record, 'timetable_revision' AS type, 'Timetable revision' AS source, 'Recorded' AS status, CASE WHEN UPPER(tr.state) = 'PUBLISHED' THEN 'Timetable revision published.' WHEN UPPER(tr.state) = 'PREPARED' THEN 'Timetable revision prepared.' WHEN UPPER(tr.state) = 'DRAFT' THEN 'Timetable revision draft created.' ELSE 'Timetable revision recorded.' END AS summary");

        $gradeOutcomes = DB::table('grade_outcome_events as goe')
            ->leftJoin('users as u', 'u.id', '=', 'goe.recorded_by')
            ->whereIn('goe.event_type', self::supportedGradeOutcomeEvents())
            ->selectRaw("goe.id AS sort_id, CONCAT('grade_outcome:', goe.id) AS reference_id, COALESCE(goe.released_at, goe.created_at) AS occurred_at, goe.recorded_by AS actor_id, ".self::actorSql('u', 'goe.recorded_by').' AS actor, '.self::actorRoleResolutionSql('u', 'goe.recorded_by')." AS actor_role, CONCAT('GradeOutcome #', goe.id) AS affected_record, goe.event_type AS type, 'Grade outcome' AS source, 'Recorded' AS status, CASE WHEN goe.event_type = 'INITIAL_RELEASE' THEN 'Initial grade roster outcome released.' WHEN goe.event_type = 'PENDING_REPLACEMENT' THEN 'Pending grade replacement recorded.' WHEN goe.event_type = 'INC_RESOLUTION' THEN 'Incomplete grade resolution recorded.' WHEN goe.event_type = 'POSTED_CORRECTION' THEN 'Posted grade correction recorded.' WHEN goe.event_type = 'LIFECYCLE_OUTCOME' THEN 'Academic lifecycle outcome recorded.' ELSE 'Grade outcome recorded.' END AS summary");

        $gradeRosters = DB::table('grade_roster_versions as grv')
            ->leftJoin('users as u', 'u.id', '=', 'grv.submitted_by')
            ->whereIn('grv.state', ['SUBMITTED', 'RELEASED', 'APPROVED', 'DRAFT', 'submitted', 'released', 'approved', 'draft'])
            ->selectRaw("grv.id AS sort_id, CONCAT('grade_roster:', grv.id) AS reference_id, COALESCE(grv.released_at, grv.submitted_at, grv.created_at) AS occurred_at, grv.submitted_by AS actor_id, ".self::actorSql('u', 'grv.submitted_by').' AS actor, '.self::actorRoleResolutionSql('u', 'grv.submitted_by')." AS actor_role, CONCAT('GradeRoster #', grv.grade_roster_id) AS affected_record, 'grade_roster_submission' AS type, 'Grade roster' AS source, 'Recorded' AS status, CASE WHEN UPPER(grv.state) = 'RELEASED' THEN 'Grade roster version released.' WHEN UPPER(grv.state) = 'SUBMITTED' THEN 'Grade roster version submitted.' WHEN UPPER(grv.state) = 'APPROVED' THEN 'Grade roster version approved.' ELSE 'Grade roster version recorded.' END AS summary");

        $lifecycle = DB::table('student_lifecycle_changes as slc')
            ->leftJoin('users as u', 'u.id', '=', 'slc.recorded_by')
            ->whereIn('slc.type', self::supportedStudentLifecycleChangeTypes())
            ->selectRaw("slc.id AS sort_id, CONCAT('lifecycle:', slc.id) AS reference_id, COALESCE(slc.created_at, NOW()) AS occurred_at, slc.recorded_by AS actor_id, ".self::actorSql('u', 'slc.recorded_by').' AS actor, '.self::actorRoleResolutionSql('u', 'slc.recorded_by')." AS actor_role, CONCAT('StudentLifecycle #', slc.student_profile_id) AS affected_record, slc.type AS type, 'Student lifecycle' AS source, 'Recorded' AS status, CASE WHEN slc.type = 'SUBJECT_DROP' THEN 'Student subject drop recorded.' WHEN slc.type = 'WITHDRAWAL' THEN 'Student withdrawal recorded.' WHEN slc.type = 'LEAVE_OF_ABSENCE' THEN 'Student leave of absence recorded.' WHEN slc.type = 'TRANSFER_OUT' THEN 'Student transfer out recorded.' WHEN slc.type = 'REACTIVATION' THEN 'Student reactivation recorded.' WHEN slc.type = 'INACTIVATION' THEN 'Student inactivation recorded.' WHEN slc.type = 'PROGRAM_SHIFT' THEN 'Student program shift recorded.' WHEN slc.type = 'COMPLETION' THEN 'Student completion recorded.' ELSE 'Student lifecycle change recorded.' END AS summary");

        $graduationApps = DB::table('graduation_applications as ga')
            ->leftJoin('users as u', 'u.id', '=', 'ga.applied_by')
            ->whereIn('ga.state', ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'CONFERRED', 'Draft', 'Submitted', 'UnderReview', 'Approved', 'Rejected'])
            ->selectRaw("ga.id AS sort_id, CONCAT('grad_app:', ga.id) AS reference_id, ga.applied_at AS occurred_at, ga.applied_by AS actor_id, ".self::actorSql('u', 'ga.applied_by').' AS actor, '.self::actorRoleResolutionSql('u', 'ga.applied_by')." AS actor_role, CONCAT('GraduationApplication #', ga.id) AS affected_record, 'graduation_application' AS type, 'Graduation application' AS source, 'Recorded' AS status, CASE WHEN UPPER(ga.state) IN ('APPROVED', 'CONFERRED') THEN 'Graduation application approved.' WHEN UPPER(ga.state) = 'REJECTED' THEN 'Graduation application rejected.' WHEN UPPER(ga.state) = 'UNDER_REVIEW' THEN 'Graduation application placed under review.' WHEN UPPER(ga.state) = 'SUBMITTED' THEN 'Graduation application submitted.' ELSE 'Graduation application state recorded.' END AS summary");

        $degreeConferrals = DB::table('degree_conferrals as dc')
            ->leftJoin('users as u', 'u.id', '=', 'dc.recorded_by')
            ->selectRaw("dc.id AS sort_id, CONCAT('degree_conferral:', dc.id) AS reference_id, dc.recorded_at AS occurred_at, dc.recorded_by AS actor_id, ".self::actorSql('u', 'dc.recorded_by').' AS actor, '.self::actorRoleResolutionSql('u', 'dc.recorded_by')." AS actor_role, CONCAT('DegreeConferral #', dc.id) AS affected_record, 'degree_conferral' AS type, 'Degree conferral' AS source, 'Recorded' AS status, 'Official degree conferral recorded.' AS summary");

        $readiness = DB::table('completion_readiness_versions as crv')
            ->leftJoin('users as u', 'u.id', '=', 'crv.generated_by')
            ->whereIn('crv.state', self::supportedCompletionReadinessStates())
            ->selectRaw("crv.id AS sort_id, CONCAT('readiness:', crv.id) AS reference_id, crv.generated_at AS occurred_at, crv.generated_by AS actor_id, ".self::actorSql('u', 'crv.generated_by').' AS actor, '.self::actorRoleResolutionSql('u', 'crv.generated_by')." AS actor_role, CONCAT('CompletionReadiness #', crv.student_profile_id) AS affected_record, 'completion_readiness' AS type, 'Completion readiness' AS source, 'Recorded' AS status, CASE WHEN UPPER(crv.state) IN ('READYFORCONFERRAL', 'READY', 'COMPLETE', 'CONFERRED', 'ELIGIBLETOAPPLY') THEN 'Student completion readiness confirmed.' WHEN UPPER(crv.state) IN ('NOTELIGIBLE', 'NOT_READY', 'AWAITINGRESULTSORCLEARANCE') THEN 'Student completion readiness deficiency recorded.' ELSE 'Completion readiness evaluation recorded.' END AS summary");

        $assessments = DB::table('assessments as ass')
            ->leftJoin('users as u', 'u.id', '=', 'ass.activated_by')
            ->whereIn('ass.state', ['DRAFT', 'ACTIVE', 'SUPERSEDED', 'CANCELLED', 'Draft', 'Active', 'Superseded', 'Cancelled'])
            ->selectRaw("ass.id AS sort_id, CONCAT('assessment:', ass.id) AS reference_id, COALESCE(ass.activated_at, ass.created_at) AS occurred_at, ass.activated_by AS actor_id, ".self::actorSql('u', 'ass.activated_by').' AS actor, '.self::actorRoleResolutionSql('u', 'ass.activated_by')." AS actor_role, CONCAT('Assessment #', ass.id) AS affected_record, 'fee_assessment' AS type, 'Fee assessment' AS source, 'Recorded' AS status, CASE WHEN UPPER(ass.state) = 'ACTIVE' THEN 'Fee assessment activated.' WHEN UPPER(ass.state) = 'SUPERSEDED' THEN 'Fee assessment superseded.' WHEN UPPER(ass.state) = 'CANCELLED' THEN 'Fee assessment cancelled.' ELSE 'Fee assessment recorded.' END AS summary");

        $payments = DB::table('payment_evidence_versions as pev')
            ->leftJoin('users as u', 'u.id', '=', 'pev.submitted_by')
            ->whereIn('pev.state', ['SUBMITTED', 'VERIFIED', 'REJECTED', 'PENDING', 'Submitted', 'Verified', 'Rejected', 'Pending'])
            ->selectRaw("pev.id AS sort_id, CONCAT('payment_evidence:', pev.id) AS reference_id, COALESCE(pev.reviewed_at, pev.submitted_at, pev.created_at) AS occurred_at, pev.submitted_by AS actor_id, ".self::actorSql('u', 'pev.submitted_by').' AS actor, '.self::actorRoleResolutionSql('u', 'pev.submitted_by')." AS actor_role, CONCAT('PaymentEvidence #', pev.id) AS affected_record, 'payment_evidence' AS type, 'Payment evidence' AS source, 'Recorded' AS status, CASE WHEN UPPER(pev.state) = 'VERIFIED' THEN 'Payment evidence verified.' WHEN UPPER(pev.state) = 'REJECTED' THEN 'Payment evidence rejected.' WHEN UPPER(pev.state) = 'SUBMITTED' THEN 'Payment evidence submitted.' ELSE 'Payment evidence recorded.' END AS summary");

        $transcripts = DB::table('transcript_issuance_events as tie')
            ->leftJoin('users as u', 'u.id', '=', 'tie.recorded_by')
            ->whereIn('tie.type', self::supportedTranscriptIssuanceEvents())
            ->selectRaw("tie.id AS sort_id, CONCAT('transcript_issuance:', tie.id) AS reference_id, tie.recorded_at AS occurred_at, tie.recorded_by AS actor_id, ".self::actorSql('u', 'tie.recorded_by').' AS actor, '.self::actorRoleResolutionSql('u', 'tie.recorded_by')." AS actor_role, CONCAT('TranscriptIssuance #', tie.id) AS affected_record, tie.type AS type, 'Transcript issuance' AS source, 'Recorded' AS status, CASE WHEN tie.type = 'Issued' THEN 'Official transcript issued.' WHEN tie.type = 'Voided' THEN 'Official transcript voided.' WHEN tie.type = 'Replacement' THEN 'Replacement transcript issued.' WHEN tie.type = 'Superseded' THEN 'Official transcript superseded.' ELSE 'Transcript issuance event recorded.' END AS summary");

        $union = $activity
            ->unionAll($admissionCycles)
            ->unionAll($admissionApps)
            ->unionAll($regCases)
            ->unionAll($timetables)
            ->unionAll($gradeOutcomes)
            ->unionAll($gradeRosters)
            ->unionAll($lifecycle)
            ->unionAll($graduationApps)
            ->unionAll($degreeConferrals)
            ->unionAll($readiness)
            ->unionAll($assessments)
            ->unionAll($payments)
            ->unionAll($transcripts);

        return DB::query()->fromSub($union, 'institutional_evidence');
    }

    private function systemEventsQuery(): Builder
    {
        $authentication = DB::table('activity_log as activity')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'activity.causer_id')
            ->where(function (Builder $query): void {
                $query->where('activity.log_name', 'authentication')
                    ->orWhereIn('activity.event', self::authenticationEventsList());
            })
            ->whereNotNull('activity.event')
            ->where('activity.event', '!=', '')
            ->selectRaw("activity.id AS sort_id, CONCAT('authentication:', activity.id) AS reference_id, activity.created_at AS occurred_at, activity.causer_id AS actor_id, ".self::actorSql('actor_user', 'activity.causer_id').' AS actor, '.self::actorRoleResolutionSql('actor_user', 'activity.causer_id')." AS actor_role, CASE WHEN activity.subject_type IS NOT NULL AND activity.subject_id IS NOT NULL THEN CONCAT(REPLACE(activity.subject_type, 'App\\\\Models\\\\', ''), ' #', activity.subject_id) ELSE 'Authentication boundary' END AS affected_record, activity.event AS type, 'Authentication' AS source, CASE WHEN activity.event = 'login_failed' THEN 'Attention' ELSE 'Recorded' END AS status, CASE WHEN activity.event = 'login' THEN 'User authenticated successfully.' WHEN activity.event = 'login_failed' THEN 'Authentication attempt failed.' WHEN activity.event = 'logout' THEN 'User signed out.' WHEN activity.event = 'password_changed' THEN 'User password was changed.' WHEN activity.event = 'password_recovery_completed' THEN 'Password recovery completed.' WHEN activity.event = 'mfa_enabled' THEN 'Multi-factor authentication enabled.' WHEN activity.event = 'mfa_disabled' THEN 'Multi-factor authentication disabled.' WHEN activity.event = 'mfa_challenge_succeeded' THEN 'MFA challenge verified successfully.' WHEN activity.event = 'mfa_recovery_code_used' THEN 'MFA recovery code used for sign-in.' WHEN activity.event = 'mfa_recovery_codes_regenerated' THEN 'MFA recovery codes regenerated.' WHEN activity.event = 'email_verified' THEN 'User email address verified.' WHEN activity.event = 'staff_mfa_reset' THEN 'Staff MFA reset by administrator.' WHEN activity.event = 'sign_in_email_changed' THEN 'Sign-in email address changed.' WHEN activity.event = 'staff_email_changed' THEN 'Staff email address change recorded.' ELSE '__HUMANIZE_SUMMARY__' END AS summary");

        $operational = DB::table('operational_events as event')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'event.user_id')
            ->whereNotNull('event.event_type')
            ->where('event.event_type', '!=', '')
            ->selectRaw("event.id AS sort_id, CONCAT('operational:', event.id) AS reference_id, event.occurred_at AS occurred_at, event.user_id AS actor_id, ".self::actorSql('actor_user', 'event.user_id').' AS actor, '.self::actorRoleResolutionSql('actor_user', 'event.user_id')." AS actor_role, CASE WHEN event.related_record_type IS NOT NULL AND event.related_record_id IS NOT NULL THEN CONCAT(REPLACE(event.related_record_type, 'App\\\\Models\\\\', ''), ' #', event.related_record_id) ELSE CONCAT('Integration: ', COALESCE(event.integration, 'system')) END AS affected_record, event.event_type AS type, 'Operational event' AS source, CASE WHEN UPPER(event.status) IN ('PROCESSED', 'SUCCESS', 'COMPLETED') THEN 'Recorded' WHEN UPPER(event.status) IN ('FAILED', 'REVIEW_REQUIRED', 'ERROR') THEN 'Attention' ELSE 'Pending' END AS status, CASE WHEN event.event_type = 'mail_self_test_accepted' THEN 'Self-test diagnostic email accepted by local transport.' WHEN event.event_type = 'mail_self_test_failed' THEN 'Self-test diagnostic email failed local transport dispatch.' WHEN event.event_type = 'solver_health_probe' AND UPPER(event.status) IN ('PROCESSED', 'SUCCESS', 'COMPLETED') THEN 'Scheduling solver health probe succeeded.' WHEN event.event_type = 'solver_health_probe' THEN 'Scheduling solver health probe failed.' WHEN event.event_type = 'solver_dispatch_attempt' THEN 'Timetable solver dispatch attempt recorded.' WHEN event.event_type = 'integration_failure' THEN 'External service integration failure recorded.' WHEN event.event_type = 'backup_evidence_recorded' THEN 'Local database backup evidence recorded.' WHEN event.event_type = 'restore_evidence_recorded' THEN 'Local database restore verification evidence recorded.' WHEN event.event_type = 'schedule_revision_pending' THEN 'Timetable revision dispatch pending.' WHEN event.event_type = 'schedule_revision_processed' THEN 'Timetable revision processed successfully.' WHEN event.event_type = 'schedule_revision_failed' THEN 'Timetable revision processing failed.' WHEN event.event_type = 'admission_application_submitted' THEN 'Admission application intake submitted.' WHEN event.event_type LIKE '%_email' THEN 'Transactional notification email dispatched.' ELSE '__HUMANIZE_SUMMARY__' END AS summary");

        return DB::query()->fromSub($authentication->unionAll($operational), 'evidence');
    }

    private function outputAccessQuery(): Builder
    {
        return DB::table('output_access_logs as output')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'output.actor_user_id')
            ->selectRaw("output.id AS sort_id, CONCAT('output:', output.id) AS reference_id, output.occurred_at AS occurred_at, output.actor_user_id AS actor_id, CASE WHEN actor_user.name IS NOT NULL AND actor_user.name != '' THEN actor_user.name WHEN output.actor_role IS NOT NULL AND output.actor_role != '' THEN output.actor_role ELSE 'Unattributed' END AS actor, CASE WHEN output.actor_role IS NOT NULL AND output.actor_role != '' THEN output.actor_role WHEN output.actor_user_id IS NOT NULL THEN COALESCE(".self::actorRoleSql('actor_user').", 'Unattributed') ELSE 'Unattributed' END AS actor_role, CONCAT(output.source_record_type, ' #', output.source_record_id) AS affected_record, COALESCE(output.output_type, 'output') AS type, 'Output access' AS source, CASE WHEN LOWER(output.status) IN ('generated', 'logged', 'issued', 'reissued', 'recorded', 'success', 'completed') THEN 'Recorded' WHEN LOWER(output.status) IN ('pending', 'requested', 'queued', 'in_progress', 'processing') THEN 'Pending' WHEN LOWER(output.status) IN ('no_rows', 'empty', 'no_artifact') THEN 'No artifact' WHEN LOWER(output.status) IN ('failed', 'failure', 'error', 'denied', 'blocked', 'rejected', 'review_required', 'tampered', 'unauthorized', 'stale', 'inaccessible') THEN 'Attention' ELSE 'Unknown' END AS status, CASE WHEN LOWER(output.status) IN ('failed', 'failure', 'error', 'denied', 'blocked', 'rejected', 'review_required', 'tampered', 'unauthorized') THEN 'Output access was denied or failed.' WHEN LOWER(output.status) IN ('no_rows', 'empty', 'no_artifact') THEN 'No artifact was generated for this output request.' WHEN LOWER(output.status) IN ('pending', 'requested', 'queued', 'in_progress', 'processing') THEN 'Output request is pending.' WHEN LOWER(output.status) = 'stale' THEN 'Output record is stale and requires attention.' WHEN LOWER(output.status) = 'inaccessible' THEN 'Output is currently inaccessible.' WHEN LOWER(output.status) IN ('generated', 'logged', 'issued', 'reissued', 'recorded', 'success', 'completed') THEN 'An authorized output or export access was recorded.' ELSE 'Output access state is unclassified.' END AS summary");
    }

    /** @return list<string> */
    public static function institutionalGenericEvents(): array
    {
        return [
            'created',
            'updated',
            'deleted',
        ];
    }

    /** @return list<class-string> */
    public static function supportedInstitutionalGenericSubjects(): array
    {
        return [
            FaqEntry::class,
            PublicNotice::class,
            SystemSetting::class,
            User::class,
            CurriculumVersion::class,
            CourseSpecification::class,
            AcademicYear::class,
            Term::class,
            FeePlan::class,
            AdmissionCycle::class,
            Program::class,
        ];
    }

    /**
     * Retained for account access-history widgets (e.g. UserInfolist).
     *
     * @return list<string>
     */
    public static function specificInstitutionalActivityEvents(): array
    {
        return [
            'staff_access_changed',
            'staff_account_disabled',
            'staff_account_reactivated',
            'staff_invited',
            'staff_invitation_activated',
            'staff_email_changed',
            'hold_created',
            'hold_waived',
            'hold_resolved',
            'hold_expired',
            'curriculum_workbench_row_created',
            'curriculum_workbench_placement_updated',
            'curriculum_workbench_specification_updated',
            'curriculum_activated',
            'course_specification_draft_copied',
            'course_specification_activated',
            'financial_accommodation_transitioned',
            'graduation_snapshot_generated',
            'admission_assisted_draft_saved',
            'admission_assisted_draft_discarded',
            'published',
            'unpublished',
            'reordered',
            'failed_jobs_retried',
        ];
    }

    /** @return list<string> */
    public static function readOnlyAccessEvents(): array
    {
        return [
            'payment_evidence_accessed',
            'admission_evidence_accessed',
            'output_accessed',
        ];
    }

    /** @return list<string> */
    private static function authenticationEventsList(): array
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
            'staff_email_changed',
            'staff_mfa_reset',
        ];
    }

    /** @return list<string> */
    public static function supportedAdmissionCycleEvents(): array
    {
        return [
            'Published',
            'DatesChanged',
            'Cancelled',
            'published',
            'dates_changed',
            'cancelled',
        ];
    }

    /** @return list<string> */
    public static function supportedAdmissionApplicationEvents(): array
    {
        return [
            'Submitted',
            'Resubmitted',
            'CorrectionRequested',
            'Withdrawn',
            'Reopened',
            'DecisionRecorded',
            'CredentialResultRecorded',
            'ReadinessBecameTrue',
            'ReadinessBecameFalse',
        ];
    }

    /** @return list<string> */
    public static function supportedRegistrationCaseEvents(): array
    {
        return [
            'CaseStarted',
            'OutcomeAssigned',
            'Enrolled',
            'Cancelled',
            'Dropped',
            'Reinstated',
            'TransitionRecorded',
        ];
    }

    /** @return list<string> */
    public static function supportedGradeOutcomeEvents(): array
    {
        return [
            'INITIAL_RELEASE',
            'PENDING_REPLACEMENT',
            'INC_RESOLUTION',
            'POSTED_CORRECTION',
            'LIFECYCLE_OUTCOME',
        ];
    }

    /** @return list<string> */
    public static function supportedStudentLifecycleChangeTypes(): array
    {
        return [
            'SUBJECT_DROP',
            'WITHDRAWAL',
            'LEAVE_OF_ABSENCE',
            'TRANSFER_OUT',
            'REACTIVATION',
            'INACTIVATION',
            'PROGRAM_SHIFT',
            'COMPLETION',
        ];
    }

    /** @return list<string> */
    public static function supportedTranscriptIssuanceEvents(): array
    {
        return [
            'Issued',
            'Voided',
            'Replacement',
            'Superseded',
        ];
    }

    /** @return list<string> */
    public static function supportedCompletionReadinessStates(): array
    {
        return [
            'NotEligible',
            'EligibleToApply',
            'AwaitingResultsOrClearance',
            'ReadyForConferral',
            'Conferred',
            'COMPLETE',
            'IN_PROGRESS',
            'READY',
            'NOT_READY',
            'REVIEW_REQUIRED',
            'Complete',
            'InProgress',
            'Ready',
            'NotReady',
            'ReviewRequired',
        ];
    }
}
