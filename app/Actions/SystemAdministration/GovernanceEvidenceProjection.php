<?php

namespace App\Actions\SystemAdministration;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            self::InstitutionalChanges => 'Institutional Changes',
            self::SystemEvents => 'System Events',
            self::OutputAccess => 'Output and Export Access',
            self::PrivacyRetention => 'Privacy and Retention Boundary',
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $filters
     */
    public function paginate(string $tab, int $page, int $perPage, ?string $search, array $filters): LengthAwarePaginator
    {
        $query = DB::query()->fromSub($this->query($tab), 'governance_evidence');
        $actorId = $filters['actor']['value'] ?? null;
        $type = $filters['type']['value'] ?? null;
        $from = $filters['date']['from'] ?? null;
        $until = $filters['date']['until'] ?? null;

        $query
            ->when(filled($actorId), fn (Builder $builder): Builder => $builder->where('actor_id', (int) $actorId))
            ->when(filled($type), fn (Builder $builder): Builder => $builder->where('type', (string) $type))
            ->when(filled($from), fn (Builder $builder): Builder => $builder->whereDate('occurred_at', '>=', (string) $from))
            ->when(filled($until), fn (Builder $builder): Builder => $builder->whereDate('occurred_at', '<=', (string) $until));

        $safeSearch = Str::of((string) $search)->trim()->limit(100, '')->toString();
        if ($safeSearch !== '') {
            $query->where(function (Builder $builder) use ($safeSearch): void {
                $builder
                    ->where('actor', 'like', "%{$safeSearch}%")
                    ->orWhere('type', 'like', "%{$safeSearch}%")
                    ->orWhere('source', 'like', "%{$safeSearch}%")
                    ->orWhere('summary', 'like', "%{$safeSearch}%");
            });
        }

        $total = (clone $query)->count();
        $rows = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('sort_id')
            ->forPage($page, $perPage)
            ->get()
            ->mapWithKeys(function (object $row): array {
                $record = [
                    'reference_id' => (string) $row->reference_id,
                    'occurred_at' => (string) $row->occurred_at,
                    'actor' => (string) $row->actor,
                    'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
                    'type' => Str::headline((string) $row->type),
                    'source' => (string) $row->source,
                    'status' => (string) $row->status,
                    'summary' => (string) $row->summary,
                ];

                return [$record['reference_id'] => $record];
            });

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
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

        [$prefix, $rawId] = explode(':', $referenceId, 2);
        $id = (int) $rawId;

        if ($id <= 0) {
            return null;
        }

        return match ($prefix) {
            'activity', 'authentication' => DB::table('activity_log as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.causer_id')
                ->where('a.id', $id)
                ->selectRaw("a.id AS sort_id, CONCAT('{$prefix}:', a.id) AS reference_id, a.created_at AS occurred_at, a.causer_id AS actor_id, COALESCE(NULLIF(u.name, ''), 'System') AS actor, a.event AS type, 'Activity Log' AS source, CASE WHEN a.event = 'login_failed' THEN 'Attention' ELSE 'Recorded' END AS status, COALESCE(a.description, 'An institutional change was recorded.') AS summary")
                ->first() ? (array) DB::table('activity_log as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.causer_id')
                ->where('a.id', $id)
                ->selectRaw("CONCAT('{$prefix}:', a.id) AS reference_id, a.created_at AS occurred_at, COALESCE(NULLIF(u.name, ''), 'System') AS actor, a.event AS type, 'Activity Log' AS source, CASE WHEN a.event = 'login_failed' THEN 'Attention' ELSE 'Recorded' END AS status, COALESCE(a.description, 'An institutional change was recorded.') AS summary")
                ->first() : null,

            'operational' => DB::table('operational_events as e')
                ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.id', $id)
                ->selectRaw("CONCAT('operational:', e.id) AS reference_id, e.occurred_at AS occurred_at, COALESCE(NULLIF(u.name, ''), 'System') AS actor, e.event_type AS type, 'Operational event' AS source, CASE WHEN e.status = 'PROCESSED' THEN 'Recorded' WHEN e.status IN ('FAILED', 'REVIEW_REQUIRED') THEN 'Attention' ELSE 'Pending' END AS status, 'A classified operational event was recorded.' AS summary")
                ->first() ? (array) DB::table('operational_events as e')
                ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.id', $id)
                ->selectRaw("CONCAT('operational:', e.id) AS reference_id, e.occurred_at AS occurred_at, COALESCE(NULLIF(u.name, ''), 'System') AS actor, e.event_type AS type, 'Operational event' AS source, CASE WHEN e.status = 'PROCESSED' THEN 'Recorded' WHEN e.status IN ('FAILED', 'REVIEW_REQUIRED') THEN 'Attention' ELSE 'Pending' END AS status, 'A classified operational event was recorded.' AS summary")
                ->first() : null,

            'output' => DB::table('output_access_logs as o')
                ->leftJoin('users as u', 'u.id', '=', 'o.actor_user_id')
                ->where('o.id', $id)
                ->selectRaw("CONCAT('output:', o.id) AS reference_id, o.occurred_at AS occurred_at, COALESCE(NULLIF(u.name, ''), 'System') AS actor, o.output_type AS type, 'Output access' AS source, CASE WHEN LOWER(o.status) IN ('logged', 'generated', 'downloaded', 'viewed', 'printed', 'success', 'completed') THEN 'Recorded' WHEN LOWER(o.status) = 'no_rows' THEN 'No artifact' ELSE 'Attention' END AS status, 'An authorized output or export access was recorded.' AS summary")
                ->first() ? (array) DB::table('output_access_logs as o')
                ->leftJoin('users as u', 'u.id', '=', 'o.actor_user_id')
                ->where('o.id', $id)
                ->selectRaw("CONCAT('output:', o.id) AS reference_id, o.occurred_at AS occurred_at, COALESCE(NULLIF(u.name, ''), 'System') AS actor, o.output_type AS type, 'Output access' AS source, CASE WHEN LOWER(o.status) IN ('logged', 'generated', 'downloaded', 'viewed', 'printed', 'success', 'completed') THEN 'Recorded' WHEN LOWER(o.status) = 'no_rows' THEN 'No artifact' ELSE 'Attention' END AS status, 'An authorized output or export access was recorded.' AS summary")
                ->first() : null,

            default => null,
        };
    }

    /** @return array<int, string> */
    public function actorOptions(): array
    {
        return User::query()
            ->whereHas('roles')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn (?string $name): string => filled($name) ? (string) $name : 'Staff account')
            ->all();
    }

    /** @return array<string, string> */
    public function typeOptions(string $tab): array
    {
        $types = match ($tab) {
            self::InstitutionalChanges => DB::table('activity_log')
                ->whereNotIn('event', $this->authenticationEvents())
                ->whereNotNull('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event')
                ->merge([
                    'admission_cycle_event',
                    'admission_application_event',
                    'registration_case_event',
                    'timetable_revision',
                    'grade_outcome_event',
                    'grade_roster_submission',
                    'lifecycle_change',
                    'graduation_application',
                    'degree_conferral',
                    'completion_readiness',
                    'fee_assessment',
                    'payment_evidence',
                    'transcript_issuance',
                ]),
            self::SystemEvents => DB::table('activity_log')
                ->whereIn('event', $this->authenticationEvents())
                ->whereNotNull('event')
                ->pluck('event')
                ->merge(DB::table('operational_events')->distinct()->orderBy('event_type')->pluck('event_type')),
            self::OutputAccess => DB::table('output_access_logs')
                ->whereNotNull('output_type')
                ->distinct()
                ->orderBy('output_type')
                ->pluck('output_type'),
            default => collect(),
        };

        return $types
            ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $type): array => [$type => Str::headline($type)])
            ->all();
    }

    private function query(string $tab): Builder
    {
        return match ($tab) {
            self::InstitutionalChanges => $this->institutionalChangesQuery(),
            self::SystemEvents => $this->systemEventsQuery(),
            self::OutputAccess => $this->outputAccessQuery(),
            default => DB::query()->fromSub(DB::query()->selectRaw("1 AS sort_id, 'none' AS reference_id, NULL AS occurred_at, NULL AS actor_id, 'System' AS actor, 'none' AS type, 'None' AS source, 'Recorded' AS status, 'No evidence.' AS summary")->whereRaw('1 = 0'), 'evidence'),
        };
    }

    private function institutionalChangesQuery(): Builder
    {
        $activity = DB::table('activity_log as activity')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'activity.causer_id')
            ->whereNotIn('activity.event', $this->authenticationEvents())
            ->selectRaw("activity.id AS sort_id, CONCAT('activity:', activity.id) AS reference_id, activity.created_at AS occurred_at, activity.causer_id AS actor_id, COALESCE(NULLIF(actor_user.name, ''), 'System') AS actor, COALESCE(activity.event, 'recorded') AS type, 'Institutional change' AS source, 'Recorded' AS status, 'An institutional change was recorded.' AS summary");

        $admissionCycles = DB::table('admission_cycle_events as ace')
            ->leftJoin('users as u', 'u.id', '=', 'ace.actor_id')
            ->selectRaw("ace.id AS sort_id, CONCAT('admission_cycle:', ace.id) AS reference_id, ace.occurred_at AS occurred_at, ace.actor_id AS actor_id, COALESCE(NULLIF(u.name, ''), 'System') AS actor, ace.event_type AS type, 'Admission cycle' AS source, 'Recorded' AS status, COALESCE(ace.reason, 'Admission cycle change recorded.') AS summary");

        $admissionApps = DB::table('admission_application_events as aae')
            ->leftJoin('users as u', 'u.id', '=', 'aae.actor_id')
            ->selectRaw("aae.id AS sort_id, CONCAT('admission_app:', aae.id) AS reference_id, aae.occurred_at AS occurred_at, aae.actor_id AS actor_id, COALESCE(NULLIF(u.name, ''), 'System') AS actor, aae.event_type AS type, 'Admission application' AS source, 'Recorded' AS status, CONCAT('Application event: ', aae.event_type) AS summary");

        $regCases = DB::table('registration_case_events as rce')
            ->leftJoin('users as u', 'u.id', '=', 'rce.actor_id')
            ->selectRaw("rce.id AS sort_id, CONCAT('reg_case:', rce.id) AS reference_id, rce.recorded_at AS occurred_at, rce.actor_id AS actor_id, COALESCE(NULLIF(u.name, ''), 'Staff') AS actor, rce.event_type AS type, 'Registration case' AS source, 'Recorded' AS status, COALESCE(rce.reason, 'Registration case transition.') AS summary");

        $timetables = DB::table('timetable_revisions as tr')
            ->leftJoin('users as u', 'u.id', '=', 'tr.published_by')
            ->selectRaw("tr.id AS sort_id, CONCAT('timetable:', tr.id) AS reference_id, COALESCE(tr.published_at, tr.prepared_at, tr.created_at) AS occurred_at, tr.published_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Registrar') AS actor, 'timetable_revision' AS type, 'Timetable revision' AS source, 'Recorded' AS status, COALESCE(tr.reason, 'Timetable revision published.') AS summary");

        $gradeOutcomes = DB::table('grade_outcome_events as goe')
            ->leftJoin('users as u', 'u.id', '=', 'goe.recorded_by')
            ->selectRaw("goe.id AS sort_id, CONCAT('grade_outcome:', goe.id) AS reference_id, COALESCE(goe.released_at, goe.created_at) AS occurred_at, goe.recorded_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Faculty/Registrar') AS actor, goe.event_type AS type, 'Grade outcome' AS source, 'Recorded' AS status, COALESCE(goe.reason, 'Grade outcome recorded.') AS summary");

        $gradeRosters = DB::table('grade_roster_versions as grv')
            ->leftJoin('users as u', 'u.id', '=', 'grv.submitted_by')
            ->selectRaw("grv.id AS sort_id, CONCAT('grade_roster:', grv.id) AS reference_id, COALESCE(grv.released_at, grv.submitted_at, grv.created_at) AS occurred_at, grv.submitted_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Faculty') AS actor, 'grade_roster_submission' AS type, 'Grade roster' AS source, 'Recorded' AS status, CONCAT('Grade roster version state: ', grv.state) AS summary");

        $lifecycle = DB::table('student_lifecycle_changes as slc')
            ->leftJoin('users as u', 'u.id', '=', 'slc.recorded_by')
            ->selectRaw("slc.id AS sort_id, CONCAT('lifecycle:', slc.id) AS reference_id, COALESCE(slc.created_at, NOW()) AS occurred_at, slc.recorded_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Registrar') AS actor, slc.type AS type, 'Student lifecycle' AS source, 'Recorded' AS status, COALESCE(slc.reason, 'Student lifecycle change.') AS summary");

        $graduationApps = DB::table('graduation_applications as ga')
            ->leftJoin('users as u', 'u.id', '=', 'ga.applied_by')
            ->selectRaw("ga.id AS sort_id, CONCAT('grad_app:', ga.id) AS reference_id, ga.applied_at AS occurred_at, ga.applied_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Student') AS actor, 'graduation_application' AS type, 'Graduation application' AS source, 'Recorded' AS status, CONCAT('Graduation application state: ', ga.state) AS summary");

        $degreeConferrals = DB::table('degree_conferrals as dc')
            ->leftJoin('users as u', 'u.id', '=', 'dc.recorded_by')
            ->selectRaw("dc.id AS sort_id, CONCAT('degree_conferral:', dc.id) AS reference_id, dc.recorded_at AS occurred_at, dc.recorded_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Registrar') AS actor, 'degree_conferral' AS type, 'Degree conferral' AS source, 'Recorded' AS status, CONCAT('Degree conferral: ', dc.degree_name) AS summary");

        $readiness = DB::table('completion_readiness_versions as crv')
            ->leftJoin('users as u', 'u.id', '=', 'crv.generated_by')
            ->selectRaw("crv.id AS sort_id, CONCAT('readiness:', crv.id) AS reference_id, crv.generated_at AS occurred_at, crv.generated_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'System') AS actor, 'completion_readiness' AS type, 'Completion readiness' AS source, 'Recorded' AS status, CONCAT('Completion readiness state: ', crv.state) AS summary");

        $assessments = DB::table('assessments as ass')
            ->leftJoin('users as u', 'u.id', '=', 'ass.activated_by')
            ->selectRaw("ass.id AS sort_id, CONCAT('assessment:', ass.id) AS reference_id, COALESCE(ass.activated_at, ass.created_at) AS occurred_at, ass.activated_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Accounting') AS actor, 'fee_assessment' AS type, 'Fee assessment' AS source, 'Recorded' AS status, CONCAT('Assessment status: ', ass.state) AS summary");

        $payments = DB::table('payment_evidence_versions as pev')
            ->leftJoin('users as u', 'u.id', '=', 'pev.submitted_by')
            ->selectRaw("pev.id AS sort_id, CONCAT('payment_evidence:', pev.id) AS reference_id, COALESCE(pev.reviewed_at, pev.submitted_at, pev.created_at) AS occurred_at, pev.submitted_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Student/Accounting') AS actor, 'payment_evidence' AS type, 'Payment evidence' AS source, 'Recorded' AS status, CONCAT('Payment evidence state: ', pev.state) AS summary");

        $transcripts = DB::table('transcript_issuance_events as tie')
            ->leftJoin('users as u', 'u.id', '=', 'tie.recorded_by')
            ->selectRaw("tie.id AS sort_id, CONCAT('transcript_issuance:', tie.id) AS reference_id, tie.recorded_at AS occurred_at, tie.recorded_by AS actor_id, COALESCE(NULLIF(u.name, ''), 'Registrar') AS actor, tie.type AS type, 'Transcript issuance' AS source, 'Recorded' AS status, COALESCE(tie.reason, 'Transcript issuance event.') AS summary");

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
            ->whereIn('activity.event', $this->authenticationEvents())
            ->selectRaw("activity.id AS sort_id, CONCAT('authentication:', activity.id) AS reference_id, activity.created_at AS occurred_at, activity.causer_id AS actor_id, COALESCE(NULLIF(actor_user.name, ''), 'System') AS actor, activity.event AS type, 'Authentication' AS source, CASE WHEN activity.event = 'login_failed' THEN 'Attention' ELSE 'Recorded' END AS status, 'An authentication event was recorded.' AS summary");

        $operational = DB::table('operational_events as event')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'event.user_id')
            ->selectRaw("event.id AS sort_id, CONCAT('operational:', event.id) AS reference_id, event.occurred_at AS occurred_at, event.user_id AS actor_id, COALESCE(NULLIF(actor_user.name, ''), 'System') AS actor, event.event_type AS type, 'Operational event' AS source, CASE WHEN event.status = 'PROCESSED' THEN 'Recorded' WHEN event.status IN ('FAILED', 'REVIEW_REQUIRED') THEN 'Attention' ELSE 'Pending' END AS status, 'A classified operational event was recorded.' AS summary");

        return DB::query()->fromSub($authentication->unionAll($operational), 'evidence');
    }

    private function outputAccessQuery(): Builder
    {
        return DB::table('output_access_logs as output')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'output.actor_user_id')
            ->selectRaw("output.id AS sort_id, CONCAT('output:', output.id) AS reference_id, output.occurred_at AS occurred_at, output.actor_user_id AS actor_id, COALESCE(NULLIF(actor_user.name, ''), 'System') AS actor, COALESCE(output.output_type, 'output') AS type, 'Output access' AS source, CASE WHEN LOWER(output.status) IN ('logged', 'generated', 'downloaded', 'viewed', 'printed', 'success', 'completed') THEN 'Recorded' WHEN LOWER(output.status) = 'no_rows' THEN 'No artifact' ELSE 'Attention' END AS status, 'An authorized output or export access was recorded.' AS summary");
    }

    /** @return list<string> */
    private function authenticationEvents(): array
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
            'staff_mfa_reset',
        ];
    }
}
