<?php

namespace App\Actions\SystemAdministration;

use App\Models\AcademicYear;
use App\Models\AdmissionCycle;
use App\Models\CalendarEvent;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\FaqEntry;
use App\Models\FeePlan;
use App\Models\Program;
use App\Models\PublicNotice;
use App\Models\SystemSetting;
use App\Models\Term;
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
                    ->orWhere('actor_role', 'like', "%{$safeSearch}%")
                    ->orWhere('affected_record', 'like', "%{$safeSearch}%")
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
                    'actor_role' => (string) ($row->actor_role ?? 'System'),
                    'affected_record' => (string) ($row->affected_record ?? 'None recorded'),
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

        $row = DB::query()->fromSub($this->query($tab), 'evidence')
            ->where('reference_id', $referenceId)
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'reference_id' => (string) $row->reference_id,
            'occurred_at' => (string) $row->occurred_at,
            'actor' => (string) $row->actor,
            'actor_id' => $row->actor_id === null ? null : (int) $row->actor_id,
            'actor_role' => (string) ($row->actor_role ?? 'System'),
            'affected_record' => (string) ($row->affected_record ?? 'None recorded'),
            'type' => Str::headline((string) $row->type),
            'source' => (string) $row->source,
            'status' => (string) $row->status,
            'summary' => (string) $row->summary,
        ];
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
            ->mapWithKeys(fn (string $type): array => [$type => Str::headline($type)])
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
        $activity = DB::table('activity_log as activity')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'activity.causer_id')
            ->where(function (Builder $query): void {
                $query->whereIn('activity.event', self::specificInstitutionalActivityEvents())
                    ->orWhere(function (Builder $genericQuery): void {
                        $genericQuery->whereIn('activity.event', self::institutionalGenericEvents())
                            ->whereIn('activity.subject_type', self::supportedInstitutionalGenericSubjects());
                    });
            })
            ->selectRaw("activity.id AS sort_id, CONCAT('activity:', activity.id) AS reference_id, activity.created_at AS occurred_at, activity.causer_id AS actor_id, ".self::actorSql('actor_user', 'activity.causer_id').' AS actor, '.self::actorRoleResolutionSql('actor_user', 'activity.causer_id')." AS actor_role, CASE WHEN activity.subject_type IS NOT NULL AND activity.subject_id IS NOT NULL THEN CONCAT(REPLACE(activity.subject_type, 'App\\\\Models\\\\', ''), ' #', activity.subject_id) ELSE 'Institutional configuration' END AS affected_record, COALESCE(activity.event, 'recorded') AS type, 'Institutional change' AS source, 'Recorded' AS status, CASE WHEN activity.event = 'user_created' THEN 'User account created.' WHEN activity.event = 'user_updated' THEN 'User account updated.' WHEN activity.event = 'user_status_changed' THEN 'User account status changed.' WHEN activity.event = 'staff_access_changed' THEN 'Staff access privileges modified.' WHEN activity.event IN ('staff_access_disabled', 'staff_account_disabled') THEN 'Staff access disabled.' WHEN activity.event IN ('staff_access_enabled', 'staff_account_reactivated') THEN 'Staff access enabled.' WHEN activity.event = 'staff_invited' THEN 'Staff invitation created.' WHEN activity.event = 'staff_invitation_activated' THEN 'Staff invitation accepted and activated.' WHEN activity.event = 'staff_email_changed' THEN 'Staff email change recorded.' WHEN activity.event = 'hold_created' THEN 'Student hold placed.' WHEN activity.event = 'hold_waived' THEN 'Student hold waived.' WHEN activity.event = 'hold_resolved' THEN 'Student hold resolved.' WHEN activity.event = 'hold_expired' THEN 'Student hold expired.' WHEN activity.event = 'curriculum_workbench_row_created' THEN 'Curriculum workbench row created.' WHEN activity.event = 'curriculum_workbench_placement_updated' THEN 'Curriculum workbench placement updated.' WHEN activity.event = 'curriculum_workbench_specification_updated' THEN 'Curriculum workbench specification updated.' WHEN activity.event = 'curriculum_approval_recorded' THEN 'Curriculum version approval recorded.' WHEN activity.event = 'curriculum_activated' THEN 'Curriculum version activated.' WHEN activity.event = 'course_specification_draft_copied' THEN 'Course specification draft copied.' WHEN activity.event = 'course_specification_activated' THEN 'Course specification activated.' WHEN activity.event = 'financial_accommodation_transitioned' THEN 'Financial accommodation transition recorded.' WHEN activity.event = 'graduation_snapshot_generated' THEN 'Graduation eligibility snapshot generated.' WHEN activity.event = 'graduation_review_batch_closed' THEN 'Graduation review batch closed.' WHEN activity.event = 'graduation_review_batch_created' THEN 'Graduation review batch created.' WHEN activity.event = 'graduation_review_member_added' THEN 'Graduation review candidate added.' WHEN activity.event = 'graduation_review_member_removed' THEN 'Graduation review candidate removed.' WHEN activity.event = 'graduation_snapshot_visibility_changed' THEN 'Graduation snapshot visibility changed.' WHEN activity.event = 'admission_assisted_draft_saved' THEN 'Assisted admission draft saved.' WHEN activity.event = 'admission_assisted_draft_discarded' THEN 'Assisted admission draft discarded.' WHEN activity.event = 'candidate_accepted' THEN 'Admission candidate accepted.' WHEN activity.event = 'candidate_rejected' THEN 'Admission candidate rejected.' WHEN activity.event = 'candidate_correction' THEN 'Admission candidate correction recorded.' WHEN activity.event = 'schedule_generation_run_published' THEN 'Class schedule generation run published.' WHEN activity.event = 'schedule_revision_published' THEN 'Timetable revision published.' WHEN activity.event = 'faculty_availability_revision_required' THEN 'Faculty availability revision requested.' WHEN activity.event = 'accounting_adjustment_posted' THEN 'Accounting adjustment posted.' WHEN activity.event = 'academic_standing_confirmed' THEN 'Academic standing confirmed.' WHEN activity.event = 'finance_cleared' THEN 'Student finance cleared.' WHEN activity.event = 'payment_confirmed' THEN 'Payment confirmed.' WHEN activity.event = 'applicant_intake_submitted' THEN 'Applicant intake submitted.' WHEN activity.event = 'applicant_intake_withdrawn' THEN 'Applicant intake withdrawn.' WHEN activity.event = 'applicant_intake_reviewed' THEN 'Applicant intake reviewed.' WHEN activity.event = 'applicant_evidence_verified' THEN 'Applicant requirement evidence verified.' WHEN activity.event = 'enrollment_edit_blocked' THEN 'Enrollment modification blocked.' WHEN activity.event = 'student_lifecycle_change_recorded' THEN 'Student lifecycle status change recorded.' WHEN activity.event = 'program_shift_applied' THEN 'Program shift applied.' WHEN activity.event = 'program_shift_cancelled' THEN 'Program shift cancelled.' WHEN activity.event = 'import_batch_state_changed' THEN 'Import batch state changed.' WHEN activity.event = 'import_batch_preview_created' THEN 'Import batch preview created.' WHEN activity.event = 'import_batch_warnings_acknowledged' THEN 'Import batch warnings acknowledged.' WHEN activity.event = 'import_batch_posted' THEN 'Import batch posted.' WHEN activity.event = 'import_batch_cancelled' THEN 'Import batch cancelled.' WHEN activity.event = 'published' THEN 'Public content published.' WHEN activity.event = 'unpublished' THEN 'Public content unpublished.' WHEN activity.event = 'reordered' THEN 'Public content display order updated.' WHEN activity.event = 'paymongo_recovered_payment_confirmed' THEN 'PayMongo recovered payment confirmed.' WHEN activity.event = 'paymongo_recovered_payment_rejected' THEN 'PayMongo recovered payment rejected.' WHEN activity.event = 'payment_checkout_attempt_created' THEN 'Payment checkout attempt initiated.' WHEN activity.event = 'created' THEN 'Institutional record created.' WHEN activity.event = 'updated' THEN 'Institutional record updated.' WHEN activity.event = 'deleted' THEN 'Institutional record deleted.' ELSE 'An institutional change was recorded.' END AS summary");

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
            ->whereIn('activity.event', $this->authenticationEvents())
            ->selectRaw("activity.id AS sort_id, CONCAT('authentication:', activity.id) AS reference_id, activity.created_at AS occurred_at, activity.causer_id AS actor_id, ".self::actorSql('actor_user', 'activity.causer_id').' AS actor, '.self::actorRoleResolutionSql('actor_user', 'activity.causer_id')." AS actor_role, CASE WHEN activity.subject_type IS NOT NULL AND activity.subject_id IS NOT NULL THEN CONCAT(REPLACE(activity.subject_type, 'App\\\\Models\\\\', ''), ' #', activity.subject_id) ELSE 'Authentication boundary' END AS affected_record, activity.event AS type, 'Authentication' AS source, CASE WHEN activity.event = 'login_failed' THEN 'Attention' ELSE 'Recorded' END AS status, CASE WHEN activity.event = 'login' THEN 'User authenticated successfully.' WHEN activity.event = 'login_failed' THEN 'Authentication attempt failed.' WHEN activity.event = 'logout' THEN 'User signed out.' WHEN activity.event = 'password_changed' THEN 'User password was changed.' WHEN activity.event = 'password_recovery_completed' THEN 'Password recovery completed.' WHEN activity.event = 'mfa_enabled' THEN 'Multi-factor authentication enabled.' WHEN activity.event = 'mfa_disabled' THEN 'Multi-factor authentication disabled.' WHEN activity.event = 'mfa_challenge_succeeded' THEN 'MFA challenge verified successfully.' WHEN activity.event = 'mfa_recovery_code_used' THEN 'MFA recovery code used for sign-in.' WHEN activity.event = 'mfa_recovery_codes_regenerated' THEN 'MFA recovery codes regenerated.' WHEN activity.event = 'email_verified' THEN 'User email address verified.' WHEN activity.event = 'staff_mfa_reset' THEN 'Staff MFA reset by administrator.' WHEN activity.event = 'sign_in_email_changed' THEN 'Sign-in email address changed.' WHEN activity.event = 'staff_email_changed' THEN 'Staff email address change recorded.' ELSE 'An authentication event was recorded.' END AS summary");

        $operational = DB::table('operational_events as event')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'event.user_id')
            ->whereIn('event.event_type', self::systemOperationalEvents())
            ->selectRaw("event.id AS sort_id, CONCAT('operational:', event.id) AS reference_id, event.occurred_at AS occurred_at, event.user_id AS actor_id, ".self::actorSql('actor_user', 'event.user_id').' AS actor, '.self::actorRoleResolutionSql('actor_user', 'event.user_id')." AS actor_role, CASE WHEN event.related_record_type IS NOT NULL AND event.related_record_id IS NOT NULL THEN CONCAT(REPLACE(event.related_record_type, 'App\\\\Models\\\\', ''), ' #', event.related_record_id) ELSE CONCAT('Integration: ', COALESCE(event.integration, 'system')) END AS affected_record, event.event_type AS type, 'Operational event' AS source, CASE WHEN UPPER(event.status) IN ('PROCESSED', 'SUCCESS', 'COMPLETED') THEN 'Recorded' WHEN UPPER(event.status) IN ('FAILED', 'REVIEW_REQUIRED', 'ERROR') THEN 'Attention' ELSE 'Pending' END AS status, CASE WHEN event.event_type = 'mail_self_test_accepted' THEN 'Self-test diagnostic email accepted by local transport.' WHEN event.event_type = 'mail_self_test_failed' THEN 'Self-test diagnostic email failed local transport dispatch.' WHEN event.event_type = 'test_email_sent' THEN 'Diagnostic test email dispatched successfully.' WHEN event.event_type = 'test_email_failed' THEN 'Diagnostic test email dispatch failed.' WHEN event.event_type = 'backup_evidence_recorded' THEN 'Local database backup evidence recorded.' WHEN event.event_type = 'restore_evidence_recorded' THEN 'Local database restore verification evidence recorded.' WHEN event.event_type = 'backup_completed' THEN 'Backup process completed.' WHEN event.event_type = 'restore_completed' THEN 'Restore verification process completed.' WHEN event.event_type = 'solver_dispatch_attempt' THEN 'Timetable solver dispatch attempt recorded.' WHEN event.event_type = 'integration_failure' THEN 'External service integration failure recorded.' WHEN event.event_type = 'safe_fixture_event' THEN 'Safe qualification fixture event recorded.' WHEN event.event_type = 'schedule_revision_pending' THEN 'Timetable revision dispatch pending.' WHEN event.event_type = 'schedule_revision_processed' THEN 'Timetable revision processed successfully.' WHEN event.event_type = 'schedule_revision_failed' THEN 'Timetable revision processing failed.' WHEN event.event_type = 'paymongo_webhook' THEN 'PayMongo webhook payload received and processed.' WHEN event.event_type = 'staff_mfa_reset_email' THEN 'Staff MFA reset notification email dispatched.' WHEN event.event_type = 'admission_application_submitted' THEN 'Admission application intake submitted.' WHEN event.event_type = 'admission_application_resubmitted' THEN 'Admission application resubmitted with corrections.' WHEN event.event_type = 'admission_correction_requested' THEN 'Admission application correction requested.' WHEN event.event_type = 'admission_application_admitted' THEN 'Admission applicant accepted and admitted.' WHEN event.event_type = 'admission_application_not_admitted' THEN 'Admission application not accepted.' WHEN event.event_type = 'admission_ready_for_enrollment' THEN 'Admission candidate cleared for enrollment.' WHEN event.event_type = 'admission_application_withdrawn' THEN 'Admission application withdrawn.' WHEN event.event_type = 'lifecycle_accounting_review' THEN 'Student lifecycle accounting review recorded.' WHEN event.event_type = 'send.webhook' THEN 'Outbound webhook payload dispatched.' WHEN event.event_type = 'checkout.recovered' THEN 'Checkout session recovered.' WHEN event.event_type = 'payment_intent.payment_successful' THEN 'Payment intent succeeded.' WHEN event.event_type LIKE 'payment.%' THEN 'Payment gateway event recorded.' WHEN event.event_type LIKE 'checkout_session.%' THEN 'Checkout session event recorded.' WHEN event.event_type LIKE '%_email' THEN 'Transactional notification email dispatched.' ELSE 'A classified operational event was recorded.' END AS summary");

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
            CalendarEvent::class,
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

    /** @return list<string> */
    public static function specificInstitutionalActivityEvents(): array
    {
        return [
            'staff_access_changed',
            'staff_access_disabled',
            'staff_account_disabled',
            'staff_access_enabled',
            'staff_account_reactivated',
            'staff_invited',
            'staff_invitation_activated',
            'staff_email_changed',
            'user_created',
            'user_updated',
            'user_status_changed',
            'candidate_accepted',
            'candidate_rejected',
            'candidate_correction',
            'hold_created',
            'hold_waived',
            'hold_resolved',
            'hold_expired',
            'curriculum_workbench_row_created',
            'curriculum_workbench_placement_updated',
            'curriculum_workbench_specification_updated',
            'curriculum_approval_recorded',
            'curriculum_activated',
            'course_specification_draft_copied',
            'course_specification_activated',
            'financial_accommodation_transitioned',
            'graduation_snapshot_generated',
            'graduation_review_batch_closed',
            'graduation_review_batch_created',
            'graduation_review_member_added',
            'graduation_review_member_removed',
            'graduation_snapshot_visibility_changed',
            'admission_assisted_draft_saved',
            'admission_assisted_draft_discarded',
            'published',
            'unpublished',
            'reordered',
            'paymongo_recovered_payment_confirmed',
            'paymongo_recovered_payment_rejected',
            'payment_checkout_attempt_created',
            'schedule_generation_run_published',
            'schedule_revision_published',
            'faculty_availability_revision_required',
            'accounting_adjustment_posted',
            'academic_standing_confirmed',
            'finance_cleared',
            'payment_confirmed',
            'applicant_intake_submitted',
            'applicant_intake_withdrawn',
            'applicant_intake_reviewed',
            'applicant_evidence_verified',
            'enrollment_edit_blocked',
            'student_lifecycle_change_recorded',
            'program_shift_applied',
            'program_shift_cancelled',
            'import_batch_state_changed',
            'import_batch_preview_created',
            'import_batch_warnings_acknowledged',
            'import_batch_posted',
            'import_batch_cancelled',
        ];
    }

    /** @return list<string> */
    public static function institutionalActivityEvents(): array
    {
        return array_merge(self::institutionalGenericEvents(), self::specificInstitutionalActivityEvents());
    }

    /** @return list<string> */
    public static function systemOperationalEvents(): array
    {
        return [
            'backup_evidence_recorded',
            'restore_evidence_recorded',
            'backup_completed',
            'restore_completed',
            'solver_dispatch_attempt',
            'integration_failure',
            'safe_fixture_event',
            'schedule_revision_pending',
            'schedule_revision_processed',
            'schedule_revision_failed',
            'paymongo_webhook',
            'paymongo_pending',
            'paymongo_processed',
            'paymongo_failed',
            'paymongo_review_required',
            'paymongo_ignored',
            'checkout_session.payment.paid',
            'payment.paid',
            'payment.failed',
            'payment.refunded',
            'payment.refund.updated',
            'checkout_session.recovered',
            'checkout_session.payment.recovered',
            'checkout.recovered',
            'payment_intent.payment_successful',
            'send.webhook',
            'mail_self_test_accepted',
            'mail_self_test_failed',
            'test_email_sent',
            'test_email_failed',
            'staff_invitation_email',
            'staff_mfa_reset_email',
            'schedule_revision_email',
            'schedule_released_email',
            'faculty_availability_requested_email',
            'payment_posted_email',
            'applicant_action_required_email',
            'applicant_approved_email',
            'admission_application_submitted',
            'admission_application_resubmitted',
            'admission_correction_requested',
            'admission_application_admitted',
            'admission_application_not_admitted',
            'admission_ready_for_enrollment',
            'admission_application_withdrawn',
            'official_enrollment_email',
            'lifecycle_accounting_review',
            'enrollment_window_email',
            'registration_proposal_email',
            'registration_payment_action_email',
            'registration_case_expiry_email',
            'registration_adjustment_email',
            'course_drop_email',
            'academic_record_updated_email',
            'grade_submission_required_email',
            'grade_roster_returned_email',
            'grade_roster_released_email',
            'inc_released_email',
            'inc_deadline_amended_email',
            'inc_resolved_email',
            'grade_correction_released_email',
            'academic_progress_lifecycle_email',
            'completion_requires_action_email',
            'conferral_recorded_email',
        ];
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
