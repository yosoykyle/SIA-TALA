<?php

namespace App\Actions\Reports;

use App\Models\Enrollment;
use App\Models\EnrollmentException;
use App\Models\EnrollmentSeatReservation;
use App\Models\FacultyTermLoadOverride;
use App\Models\FinancialAccommodation;
use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\GraduationReviewBatch;
use App\Models\GraduationSnapshot;
use App\Models\LateGradeAuthorization;
use App\Models\LedgerEntry;
use App\Models\OperationalEvent;
use App\Models\OutputAccessLog;
use App\Models\Payment;
use App\Models\PaymentScheduleRow;
use App\Models\Program;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\StudentLifecycleChange;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Policies\OperationalReportPolicy;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class OperationalReportService
{
    public const SensitivityNormal = 'NORMAL';

    public const SensitivityStudentData = 'STUDENT_DATA';

    public const SensitivityFinanceData = 'FINANCE_DATA';

    public const SensitivitySensitive = 'SENSITIVE';

    public const EnrollmentMaster = 'registrar.enrollment-master';

    public const CapacityPending = 'registrar.capacity-pending';

    public const SectionCapacity = 'registrar.section-capacity';

    public const LifecycleRegister = 'registrar.lifecycle-register';

    public const GraduationBatch = 'registrar.graduation-batch';

    public const GraduationSnapshot = 'registrar.graduation-snapshot';

    public const DailyCash = 'accounting.daily-cash';

    public const PendingOrMapping = 'accounting.pending-or-mapping';

    public const StudentLedger = 'accounting.student-ledger';

    public const TermFeeSummary = 'accounting.term-fee-summary';

    public const FinancialAccommodation = 'accounting.financial-accommodation';

    public const FacultyLoad = 'academic.faculty-load';

    public const SchedulingException = 'academic.scheduling-exception';

    public const FacultyLoadOverride = 'academic.faculty-load-override';

    public const ProgressionException = 'academic.progression-exception';

    public const GradeCorrection = 'academic.grade-correction';

    public const PendingGrade = 'academic.pending-grade';

    public const IncCompletion = 'academic.inc-completion';

    public const LateGradeAuthorization = 'academic.late-grade-authorization';

    public const UnitLoadException = 'academic.unit-load-exception';

    public const UserRole = 'audit.user-role';

    public const ActivityLog = 'audit.activity-log';

    public const GeneratedOutput = 'audit.generated-output';

    public const ReportExport = 'audit.report-export';

    public const IntegrationEvent = 'audit.integration-event';

    public function __construct(private readonly OperationalReportPolicy $policy) {}

    /** @return array<string, string> */
    public function optionsFor(User $user): array
    {
        return collect($this->catalog())
            ->filter(fn (array $definition, string $key): bool => $this->policy->view($user, $key))
            ->mapWithKeys(fn (array $definition, string $key): array => [$key => $definition['label']])
            ->all();
    }

    public function defaultFor(User $user): ?string
    {
        $key = array_key_first($this->optionsFor($user));

        return is_string($key) ? $key : null;
    }

    public function label(string $reportKey): string
    {
        return $this->definition($reportKey)['label'];
    }

    public function description(string $reportKey): string
    {
        return $this->definition($reportKey)['description'];
    }

    public function sensitivity(string $reportKey): string
    {
        return $this->definition($reportKey)['sensitivity'];
    }

    public function isSensitive(string $reportKey): bool
    {
        return $this->sensitivity($reportKey) !== self::SensitivityNormal;
    }

    /** @return list<string> */
    public function supportedFilters(string $reportKey): array
    {
        return $this->definition($reportKey)['filters'];
    }

    /** @return array<string, string> */
    public function statusOptions(string $reportKey): array
    {
        return match ($reportKey) {
            self::EnrollmentMaster, self::CapacityPending => [
                'pending_review' => 'Pending Review',
                'capacity_pending' => 'Capacity Pending',
                'payment_pending' => 'Payment Pending',
                'ready_for_official_enrollment' => 'Ready for Official Enrollment',
                'officially_enrolled' => 'Officially Enrolled',
                'cancelled' => 'Cancelled',
                'dropped' => 'Dropped',
                'withdrawn' => 'Withdrawn',
            ],
            self::SectionCapacity => Section::stateOptions(),
            self::LifecycleRegister => [
                StudentLifecycleChange::StateRecordedApproved => 'Recorded Approved',
                StudentLifecycleChange::StateApplied => 'Applied',
                StudentLifecycleChange::StateCancelled => 'Cancelled',
            ],
            self::GraduationBatch => [
                GraduationReviewBatch::StateOpen => 'Open',
                GraduationReviewBatch::StateClosed => 'Closed',
            ],
            self::GraduationSnapshot => [
                'COMPLETE' => 'Complete',
                'READY_FOR_REGISTRAR_REVIEW' => 'Ready for Registrar Review',
                'BLOCKED_MISSING_REQUIREMENT' => 'Blocked: Missing Requirement',
                'BLOCKED_FAILED_REQUIREMENT' => 'Blocked: Failed Requirement',
                'BLOCKED_PENDING_GRADE' => 'Blocked: Pending Grade',
                'BLOCKED_INC' => 'Blocked: INC',
                'BLOCKED_HOLD_OR_CLEARANCE' => 'Blocked: Hold or Clearance',
                'BLOCKED_CURRENT_ENROLLMENT' => 'Blocked: Current Enrollment Not Finalized',
            ],
            self::PendingOrMapping => ['verified' => 'Verified'],
            self::FinancialAccommodation => [
                'PENDING' => 'Pending', 'ACTIVE' => 'Active', 'FULFILLED' => 'Fulfilled',
                'DEFAULTED' => 'Defaulted', 'EXPIRED' => 'Expired', 'CANCELLED' => 'Cancelled',
            ],
            self::SchedulingException => SchedulingDemand::validationStateOptions(),
            self::FacultyLoadOverride => ['1' => 'Active', '0' => 'Inactive'],
            self::ProgressionException, self::UnitLoadException => [
                EnrollmentException::StateActive => 'Active',
                EnrollmentException::StateExpired => 'Expired',
                EnrollmentException::StateRevoked => 'Revoked',
            ],
            self::PendingGrade, self::IncCompletion => [
                GradeRosterRow::CategoryPending => 'Pending Grade',
                GradeRosterRow::CategoryIncomplete => 'Incomplete',
            ],
            self::LateGradeAuthorization => [
                LateGradeAuthorization::StateActive => 'Active',
                LateGradeAuthorization::StateExpired => 'Expired',
                LateGradeAuthorization::StateRevoked => 'Revoked',
            ],
            self::UserRole => [
                User::StatusActive => 'Active',
                User::StatusInactive => 'Inactive',
                User::StatusArchived => 'Archived',
            ],
            default => [],
        };
    }

    /**
     * @return list<array{key:string,label:string,path?:string,format?:string,badge?:bool,resolver?:string}>
     */
    public function columns(string $reportKey): array
    {
        return match ($reportKey) {
            self::EnrollmentMaster, self::CapacityPending => [
                $this->column('student_number', 'Student No.', 'studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'student_name'),
                $this->column('program', 'Program', 'studentProfile.program.code'),
                $this->column('term', 'Term', 'term.label'),
                $this->column('status', 'Enrollment Status', 'status', badge: true),
                $this->column('officially_enrolled_at', 'Official At', 'officially_enrolled_at', 'datetime'),
            ],
            self::SectionCapacity => [
                $this->column('term', 'Term', 'termOffering.term.label'),
                $this->column('section', 'Section', 'code'),
                $this->column('course', 'Course', 'termOffering.curriculumEntry.courseSpecification.course.code'),
                $this->column('capacity', 'Capacity', 'capacity'),
                $this->column('active_reservations', 'Active Reservations', 'active_reservations_count'),
                $this->column('official_enrollments', 'Official Enrollments', 'official_enrollments_count'),
                $this->column('remaining_seats', 'Remaining Seats', resolver: 'remaining_capacity'),
                $this->column('state', 'State', 'state', badge: true),
            ],
            self::LifecycleRegister => [
                $this->column('student_number', 'Student No.', 'studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'student_name'),
                $this->column('term', 'Term', 'term.label'),
                $this->column('type', 'Change Type', 'type', badge: true),
                $this->column('effective_on', 'Effective Date', 'effective_on', 'date'),
                $this->column('authority', 'Decision Authority', 'authority'),
                $this->column('recorder', 'Recorder', 'recorder.name'),
                $this->column('state', 'State', 'state', badge: true),
            ],
            self::GraduationBatch => [
                $this->column('name', 'Batch', 'name'),
                $this->column('academic_year', 'Academic Year', 'academicYear.label'),
                $this->column('term', 'Term', 'term.label'),
                $this->column('members', 'Active Members', 'active_members_count'),
                $this->column('creator', 'Created By', 'creator.name'),
                $this->column('state', 'State', 'state', badge: true),
                $this->column('created_at', 'Created At', 'created_at', 'datetime'),
            ],
            self::GraduationSnapshot => [
                $this->column('student_number', 'Student No.', 'member.studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'graduation_student_name'),
                $this->column('program', 'Program', 'member.studentProfile.program.code'),
                $this->column('batch', 'Batch', 'member.batch.name'),
                $this->column('result_status', 'Result', 'result_status', badge: true),
                $this->column('remaining_units', 'Remaining Units', resolver: 'remaining_units'),
                $this->column('version', 'Version', 'version'),
                $this->column('generated_at', 'Generated At', 'generated_at', 'datetime'),
            ],
            self::DailyCash => [
                $this->column('posted_at', 'Transaction Date/Time', 'posted_at', 'datetime'),
                $this->column('or_number', 'OR Number', 'payment.or_number'),
                $this->column('provider_reference', 'PayMongo Reference ID', 'payment.provider_reference'),
                $this->column('student_number', 'Student Number', 'studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'student_name'),
                $this->column('payment_method', 'Payment Method', 'payment.method'),
                $this->column('category', 'Allocated Category', 'category'),
                $this->column('amount', 'Amount Paid', 'amount', 'money'),
                $this->column('recorder', 'Accounting Recorder', 'poster.name'),
            ],
            self::PendingOrMapping => [
                $this->column('paid_at', 'Paid At', 'paid_at', 'datetime'),
                $this->column('provider_reference', 'Provider Reference', 'provider_reference'),
                $this->column('student_number', 'Student No.', 'studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'student_name'),
                $this->column('term', 'Term', 'term.label'),
                $this->column('method', 'Method', 'method'),
                $this->column('amount', 'Amount', 'amount', 'money'),
                $this->column('status', 'Evidence Status', 'evidence_status', badge: true),
            ],
            self::StudentLedger => [
                $this->column('posted_at', 'Posted At', 'posted_at', 'datetime'),
                $this->column('student_number', 'Student No.', 'studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'student_name'),
                $this->column('term', 'Term', 'term.label'),
                $this->column('direction', 'Direction', 'direction', badge: true),
                $this->column('category', 'Category', 'category'),
                $this->column('description', 'Description', 'description'),
                $this->column('amount', 'Amount', 'amount', 'money'),
                $this->column('source', 'Source Record', resolver: 'ledger_source'),
            ],
            self::TermFeeSummary => [
                $this->column('term', 'Term', 'term_label'),
                $this->column('program', 'Program', 'program_name'),
                $this->column('category', 'Fee / Ledger Category', 'category'),
                $this->column('entry_count', 'Payment Entries', 'entry_count'),
                $this->column('total_collected', 'Total Collected', 'total_collected', 'money'),
            ],
            self::FinancialAccommodation => [
                $this->column('student_number', 'Student No.', 'studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'student_name'),
                $this->column('term', 'Term', 'term.label'),
                $this->column('covered_amount', 'Covered Amount', 'covered_amount', 'money'),
                $this->column('next_due_date', 'Next Due Date', 'paymentScheduleRows.0.due_date', 'date'),
                $this->column('effects', 'Approved Effects', resolver: 'accommodation_effects'),
                $this->column('authority', 'Authority', 'authority'),
                $this->column('status', 'Status', 'status', badge: true),
            ],
            self::FacultyLoad => [
                $this->column('faculty', 'Faculty', 'faculty.name'),
                $this->column('term', 'Term', 'schedulingDemand.termOffering.term.label'),
                $this->column('course', 'Course', 'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course.code'),
                $this->column('section', 'Section', 'schedulingDemand.sectionDeliveryGroup.section.code'),
                $this->column('day', 'Day', resolver: 'meeting_day'),
                $this->column('time', 'Time', resolver: 'meeting_time'),
                $this->column('modality', 'Modality', 'modality', badge: true),
            ],
            self::SchedulingException => [
                $this->column('term', 'Term', 'termOffering.term.label'),
                $this->column('demand_key', 'Demand', 'demand_key'),
                $this->column('course', 'Course', 'termOffering.curriculumEntry.courseSpecification.course.code'),
                $this->column('section', 'Section', 'sectionDeliveryGroup.section.code'),
                $this->column('findings', 'Exception Summary', 'readiness_findings', 'json'),
                $this->column('validation_state', 'Validation State', 'validation_state', badge: true),
                $this->column('checked_at', 'Checked At', 'readiness_checked_at', 'datetime'),
            ],
            self::FacultyLoadOverride => [
                $this->column('faculty', 'Faculty', 'faculty.name'),
                $this->column('term', 'Term', 'term.label'),
                $this->column('default_max', 'Default Max Units', 'default_max_units_snapshot'),
                $this->column('approved_overload', 'Approved Overload Units', 'approved_overload_units'),
                $this->column('allowed_load', 'Allowed Load Units', resolver: 'allowed_load'),
                $this->column('authority', 'Authority', 'authority'),
                $this->column('reason', 'Reason', 'reason'),
                $this->column('active', 'Active', 'is_active', 'boolean', true),
            ],
            self::ProgressionException, self::UnitLoadException => [
                $this->column('student_number', 'Student No.', 'studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'student_name'),
                $this->column('term', 'Term', 'term.label'),
                $this->column('exception_type', 'Exception Type', 'exception_type', badge: true),
                $this->column('original_rule', 'Original Failed Rule', 'original_rule'),
                $this->column('scope', 'Scope', 'scope_key'),
                $this->column('approver', 'Approved By', 'approver.name'),
                $this->column('approved_at', 'Approved At', 'approved_at', 'datetime'),
                $this->column('state', 'State', 'state', badge: true),
            ],
            self::GradeCorrection => [
                $this->column('student_number', 'Student No.', 'row.courseEnrollment.enrollment.studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'grade_student_name'),
                $this->column('term', 'Term', 'row.roster.termOffering.term.label'),
                $this->column('section', 'Section', 'row.roster.section.code'),
                $this->column('previous_value', 'Previous Grade', 'previous_value'),
                $this->column('new_value', 'Corrected Grade', 'new_value'),
                $this->column('authority', 'Authority', 'authority'),
                $this->column('recorder', 'Recorded By', 'recorder.name'),
                $this->column('created_at', 'Recorded At', 'created_at', 'datetime'),
            ],
            self::PendingGrade, self::IncCompletion => [
                $this->column('student_number', 'Student No.', 'courseEnrollment.enrollment.studentProfile.student_number'),
                $this->column('student_name', 'Student Name', resolver: 'student_name'),
                $this->column('term', 'Term', 'roster.termOffering.term.label'),
                $this->column('section', 'Section', 'roster.section.code'),
                $this->column('outcome_code', 'Outcome', 'current_outcome_code'),
                $this->column('outcome_category', 'Category', 'current_outcome_category', badge: true),
                $this->column('deadline', 'Resolution Deadline', resolver: 'inc_deadline'),
                $this->column('released_at', 'Released At', 'released_at', 'datetime'),
            ],
            self::LateGradeAuthorization => [
                $this->column('faculty', 'Faculty', 'faculty.name'),
                $this->column('term', 'Term', 'termOffering.term.label'),
                $this->column('section', 'Section', 'roster.section.code'),
                $this->column('grading_period', 'Period', 'grading_period'),
                $this->column('opens_at', 'Opens At', 'opens_at', 'datetime'),
                $this->column('closes_at', 'Closes At', 'closes_at', 'datetime'),
                $this->column('approver', 'Approved By', 'approver.name'),
                $this->column('state', 'State', 'state', badge: true),
            ],
            self::UserRole => [
                $this->column('name', 'User', 'name'),
                $this->column('email', 'Email', 'email'),
                $this->column('roles', 'Roles', resolver: 'roles'),
                $this->column('status', 'Status', 'status', badge: true),
                $this->column('verified_at', 'Email Verified At', 'email_verified_at', 'datetime'),
                $this->column('created_at', 'Created At', 'created_at', 'datetime'),
            ],
            self::ActivityLog => [
                $this->column('created_at', 'Occurred At', 'created_at', 'datetime'),
                $this->column('actor', 'Actor', 'causer.name'),
                $this->column('event', 'Event', 'event', badge: true),
                $this->column('description', 'Description', 'description'),
                $this->column('subject', 'Source Record', resolver: 'activity_subject'),
                $this->column('log_name', 'Log', 'log_name'),
            ],
            self::GeneratedOutput => $this->outputAuditColumns(),
            self::ReportExport => $this->outputAuditColumns(includeFilterSummary: true),
            self::IntegrationEvent => [
                $this->column('occurred_at', 'Occurred At', 'occurred_at', 'datetime'),
                $this->column('event_domain', 'Domain', 'event_domain', badge: true),
                $this->column('integration', 'Integration', 'integration'),
                $this->column('event_type', 'Event Type', 'event_type'),
                $this->column('direction', 'Direction', 'direction'),
                $this->column('external_id', 'External ID', 'external_id'),
                $this->column('related_record', 'Related Record', resolver: 'related_record'),
                $this->column('status', 'Status', 'status', badge: true),
                $this->column('processed_at', 'Processed At', 'processed_at', 'datetime'),
            ],
            default => throw new \InvalidArgumentException("Unknown report [{$reportKey}]."),
        };
    }

    public function query(string $reportKey, User $actor): Builder
    {
        if (! $this->policy->view($actor, $reportKey)) {
            throw new AuthorizationException('You are not authorized to view this report.');
        }

        return match ($reportKey) {
            self::EnrollmentMaster => Enrollment::query()
                ->with(['studentProfile.program', 'term.academicYear'])
                ->latest('id'),
            self::CapacityPending => Enrollment::query()
                ->with(['studentProfile.program', 'term.academicYear'])
                ->where('status', 'capacity_pending')
                ->latest('id'),
            self::SectionCapacity => Section::query()
                ->with(['termOffering.term.academicYear', 'termOffering.curriculumEntry.courseSpecification.course'])
                ->withCount([
                    'seatReservations as active_reservations_count' => fn (Builder $query) => $query
                        ->whereIn('status', EnrollmentSeatReservation::capacityHoldingStatuses())
                        ->whereNull('converted_at'),
                    'seatReservations as official_enrollments_count' => fn (Builder $query) => $query->whereNotNull('converted_at'),
                ])
                ->latest('id'),
            self::LifecycleRegister => StudentLifecycleChange::query()
                ->with(['studentProfile.program', 'term.academicYear', 'recorder'])
                ->latest('effective_on'),
            self::GraduationBatch => GraduationReviewBatch::query()
                ->with(['academicYear', 'term', 'creator'])
                ->withCount(['members as active_members_count' => fn (Builder $query) => $query->where('is_active', true)])
                ->latest('id'),
            self::GraduationSnapshot => GraduationSnapshot::query()
                ->with(['member.studentProfile.program', 'member.batch.term.academicYear'])
                ->latest('generated_at'),
            self::DailyCash => LedgerEntry::query()
                ->with(['payment', 'studentProfile.program', 'term', 'poster'])
                ->where('direction', LedgerEntry::DirectionPayment)
                ->whereNotNull('payment_id')
                ->latest('posted_at'),
            self::PendingOrMapping => Payment::query()
                ->with(['studentProfile.program', 'term'])
                ->whereNull('or_number')
                ->whereHas('ledgerEntries', fn (Builder $query) => $query->where('direction', LedgerEntry::DirectionPayment))
                ->latest('paid_at'),
            self::StudentLedger => LedgerEntry::query()
                ->with(['studentProfile.program', 'term', 'poster'])
                ->latest('posted_at'),
            self::TermFeeSummary => $this->termFeeSummaryQuery(),
            self::FinancialAccommodation => FinancialAccommodation::query()
                ->with(['studentProfile.program', 'term', 'paymentScheduleRows' => fn ($query) => $query->whereIn('state', [PaymentScheduleRow::StateDue, 'partially_paid'])->oldest('due_date')])
                ->latest('id'),
            self::FacultyLoad => SectionMeeting::query()
                ->with([
                    'faculty',
                    'schedulingDemand.termOffering.term.academicYear',
                    'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                    'schedulingDemand.sectionDeliveryGroup.section',
                ])
                ->activeOfficial()
                ->latest('id'),
            self::SchedulingException => SchedulingDemand::query()
                ->with([
                    'termOffering.term.academicYear',
                    'termOffering.curriculumEntry.courseSpecification.course',
                    'sectionDeliveryGroup.section',
                ])
                ->where('validation_state', SchedulingDemand::ValidationActionRequired)
                ->latest('id'),
            self::FacultyLoadOverride => FacultyTermLoadOverride::query()
                ->with(['faculty', 'term.academicYear'])
                ->latest('id'),
            self::ProgressionException => EnrollmentException::query()
                ->with(['studentProfile.program', 'term.academicYear', 'approver'])
                ->whereIn('exception_type', [
                    EnrollmentException::TypePrerequisite,
                    EnrollmentException::TypeCorequisite,
                    EnrollmentException::TypeBridging,
                    EnrollmentException::TypeConflict,
                ])
                ->latest('approved_at'),
            self::GradeCorrection => GradeOutcomeEvent::query()
                ->with([
                    'row.courseEnrollment.enrollment.studentProfile.program',
                    'row.roster.termOffering.term.academicYear',
                    'row.roster.section',
                    'recorder',
                ])
                ->where('event_type', GradeOutcomeEvent::TypePostedCorrection)
                ->latest('created_at'),
            self::PendingGrade => GradeRosterRow::query()
                ->with(['courseEnrollment.enrollment.studentProfile.program', 'roster.termOffering.term.academicYear', 'roster.section', 'outcomeEvents'])
                ->where('current_outcome_category', GradeRosterRow::CategoryPending)
                ->latest('id'),
            self::IncCompletion => GradeRosterRow::query()
                ->with(['courseEnrollment.enrollment.studentProfile.program', 'roster.termOffering.term.academicYear', 'roster.section', 'outcomeEvents'])
                ->where(function (Builder $query): void {
                    $query->where('current_outcome_category', GradeRosterRow::CategoryIncomplete)
                        ->orWhereHas('outcomeEvents', fn (Builder $events) => $events->where('event_type', GradeOutcomeEvent::TypeIncResolution));
                })
                ->latest('id'),
            self::LateGradeAuthorization => LateGradeAuthorization::query()
                ->with(['faculty', 'termOffering.term.academicYear', 'roster.section', 'approver'])
                ->latest('opens_at'),
            self::UnitLoadException => EnrollmentException::query()
                ->with(['studentProfile.program', 'term.academicYear', 'approver'])
                ->where('exception_type', EnrollmentException::TypeUnitLoad)
                ->latest('approved_at'),
            self::UserRole => User::query()->with('roles')->latest('id'),
            self::ActivityLog => Activity::query()->with(['causer', 'subject'])->latest('id'),
            self::GeneratedOutput => OutputAccessLog::query()
                ->with('actor')
                ->where('output_type', '!=', 'REPORT')
                ->latest('occurred_at'),
            self::ReportExport => OutputAccessLog::query()
                ->with('actor')
                ->where('output_type', 'REPORT')
                ->where('action', 'EXPORT')
                ->latest('occurred_at'),
            self::IntegrationEvent => OperationalEvent::query()->with('user')->latest('occurred_at'),
            default => throw new \InvalidArgumentException("Unknown report [{$reportKey}]."),
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function applyFilters(string $reportKey, Builder $query, array $filters): Builder
    {
        $filters = $this->normalizeFilters($reportKey, $filters);

        $this->applyRelationFilter($query, $this->termRelations()[$reportKey] ?? null, 'id', $filters['term_id'] ?? null);
        $this->applyRelationFilter($query, $this->academicYearRelations()[$reportKey] ?? null, 'id', $filters['academic_year_id'] ?? null);
        $this->applyRelationFilter($query, $this->programRelations()[$reportKey] ?? null, 'id', $filters['program_id'] ?? null);
        $this->applyRelationFilter($query, $this->sectionRelations()[$reportKey] ?? null, 'id', $filters['section_id'] ?? null);

        if ($reportKey === self::TermFeeSummary) {
            $ledgerTable = (new LedgerEntry)->getTable();
            $profileTable = (new StudentProfile)->getTable();
            $query->when($filters['term_id'] ?? null, fn (Builder $builder, mixed $termId) => $builder->where("{$ledgerTable}.term_id", (int) $termId));
            $query->when($filters['program_id'] ?? null, fn (Builder $builder, mixed $programId) => $builder->where("{$profileTable}.program_id", (int) $programId));
        }

        if ($reportKey === self::SectionCapacity && filled($filters['section_id'] ?? null)) {
            $query->whereKey((int) $filters['section_id']);
        }

        $statusColumn = $this->statusColumns()[$reportKey] ?? null;
        if ($statusColumn !== null && filled($filters['status'] ?? null)) {
            $query->where($statusColumn, $this->statusValue($reportKey, (string) $filters['status']));
        }

        $dateColumn = $this->dateColumns()[$reportKey] ?? null;
        if ($dateColumn !== null) {
            $query->when($filters['date_from'] ?? null, fn (Builder $builder, mixed $date) => $builder->whereDate($dateColumn, '>=', $date));
            $query->when($filters['date_until'] ?? null, fn (Builder $builder, mixed $date) => $builder->whereDate($dateColumn, '<=', $date));
        }

        $actorColumn = $this->actorColumns()[$reportKey] ?? null;
        if ($actorColumn !== null && filled($filters['actor_id'] ?? null)) {
            $query->where($actorColumn, (int) $filters['actor_id']);
        }

        $studentColumn = $this->studentColumns()[$reportKey] ?? null;
        if ($studentColumn !== null && filled($filters['student_profile_id'] ?? null)) {
            $query->where($studentColumn, (int) $filters['student_profile_id']);
        }

        $this->applyRelationFilter(
            $query,
            $this->studentRelations()[$reportKey] ?? null,
            'id',
            $filters['student_profile_id'] ?? null,
        );

        if (in_array($reportKey, [self::GradeCorrection, self::PendingGrade, self::IncCompletion], true) && filled($filters['student_profile_id'] ?? null)) {
            $relation = $reportKey === self::GradeCorrection
                ? 'row.courseEnrollment.enrollment.studentProfile'
                : 'courseEnrollment.enrollment.studentProfile';
            $this->applyRelationFilter($query, $relation, 'id', $filters['student_profile_id']);
        }

        if (in_array($reportKey, [self::GeneratedOutput, self::ReportExport], true)) {
            $query->when($filters['output_type'] ?? null, fn (Builder $builder, mixed $type) => $builder->where('output_type', $type));
            $query->when($filters['sensitivity'] ?? null, fn (Builder $builder, mixed $sensitivity) => $builder->where('sensitivity', $sensitivity));
            $query->when($filters['source_record_id'] ?? null, fn (Builder $builder, mixed $sourceId) => $builder->where('source_record_id', (int) $sourceId));
        }

        return $query;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function normalizeFilters(string $reportKey, array $filters): array
    {
        $allowed = $this->supportedFilters($reportKey);

        return collect(Arr::only($filters, $allowed))
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->all();
    }

    /** @param array{key?:string,path?:string,format?:string,resolver?:string} $column */
    public function value(Model $record, array $column): string
    {
        $value = match ($column['resolver'] ?? null) {
            'student_name' => $this->studentName($record),
            'graduation_student_name' => $this->nameFromProfile(data_get($record, 'member.studentProfile')),
            'grade_student_name' => $this->nameFromProfile(data_get($record, 'row.courseEnrollment.enrollment.studentProfile')),
            'remaining_capacity' => max(0, (int) $record->getAttribute('capacity') - (int) $record->getAttribute('active_reservations_count') - (int) $record->getAttribute('official_enrollments_count')),
            'remaining_units' => data_get($record, 'evaluation_snapshot.remaining_units'),
            'ledger_source' => $record->getAttribute('source_type').'#'.$record->getAttribute('source_id'),
            'accommodation_effects' => $this->accommodationEffects($record),
            'meeting_day' => SectionMeeting::dayOptions()[(int) $record->getAttribute('day_of_week')] ?? (string) $record->getAttribute('day_of_week'),
            'meeting_time' => $record->getAttribute('starts_at').' - '.$record->getAttribute('ends_at'),
            'allowed_load' => number_format((float) $record->getAttribute('default_max_units_snapshot') + (float) $record->getAttribute('approved_overload_units'), 2, '.', ''),
            'inc_deadline' => optional($record->getRelationValue('outcomeEvents'))->sortByDesc('id')->first()?->deadline,
            'roles' => $record instanceof User ? $record->roles->pluck('name')->implode(', ') : '',
            'activity_subject' => class_basename((string) $record->getAttribute('subject_type')).'#'.$record->getAttribute('subject_id'),
            'output_source' => class_basename((string) $record->getAttribute('source_record_type')).'#'.$record->getAttribute('source_record_id'),
            'related_record' => class_basename((string) $record->getAttribute('related_record_type')).'#'.$record->getAttribute('related_record_id'),
            default => data_get($record, $column['path'] ?? $column['key'] ?? ''),
        };

        return $this->formatValue($value, $column['format'] ?? 'text');
    }

    /** @return array<string, array{label:string,description:string,sensitivity:string,filters:list<string>}> */
    private function catalog(): array
    {
        $studentFilters = ['academic_year_id', 'term_id', 'program_id', 'status', 'date_from', 'date_until', 'student_profile_id'];

        return [
            self::EnrollmentMaster => $this->definitionRow('Enrollment Master List', 'Official and in-process term enrollment records from the enrollment source.', self::SensitivityStudentData, $studentFilters),
            self::CapacityPending => $this->definitionRow('Capacity Pending List', 'Students awaiting Registrar section placement or capacity confirmation.', self::SensitivityStudentData, $studentFilters),
            self::SectionCapacity => $this->definitionRow('Section Capacity Summary', 'Section capacity, active reservations, official enrollment occupancy, and remaining seats.', self::SensitivityNormal, ['academic_year_id', 'term_id', 'program_id', 'section_id', 'status']),
            self::LifecycleRegister => $this->definitionRow('Student Lifecycle Change Register', 'Approved lifecycle results without private source-form references or staff-only evidence.', self::SensitivityStudentData, $studentFilters),
            self::GraduationBatch => $this->definitionRow('Graduation Review Batch List', 'Registrar review batches and active membership counts.', self::SensitivityStudentData, ['academic_year_id', 'term_id', 'status', 'date_from', 'date_until', 'actor_id']),
            self::GraduationSnapshot => $this->definitionRow('Graduation Eligibility Snapshot', 'Generated eligibility snapshot results without private source-reference payloads.', self::SensitivityStudentData, $studentFilters),
            self::DailyCash => $this->definitionRow('Daily Cash Collection / Daily Turnover', 'Posted payment ledger rows used for cash, OR, and gateway turnover checks.', self::SensitivityFinanceData, ['term_id', 'program_id', 'date_from', 'date_until', 'actor_id', 'student_profile_id']),
            self::PendingOrMapping => $this->definitionRow('Pending OR-mapping Reconciliation Exceptions', 'Verified and posted payments still awaiting physical OR mapping.', self::SensitivityFinanceData, ['term_id', 'program_id', 'status', 'date_from', 'date_until', 'student_profile_id']),
            self::StudentLedger => $this->definitionRow('Student Ledger Statement', 'Append-only ledger entries for one authorized student or filtered term.', self::SensitivityFinanceData, ['term_id', 'program_id', 'date_from', 'date_until', 'actor_id', 'student_profile_id']),
            self::TermFeeSummary => $this->definitionRow('Term Fee Summary', 'Posted payment totals grouped by term, program, and ledger category.', self::SensitivityFinanceData, ['term_id', 'program_id', 'date_from', 'date_until']),
            self::FinancialAccommodation => $this->definitionRow('Financial Accommodation List', 'Accommodation status and approved effects; certification and private evidence are excluded.', self::SensitivityFinanceData, $studentFilters),
            self::FacultyLoad => $this->definitionRow('Faculty Load Report', 'Published faculty meeting assignments from the official schedule.', self::SensitivityNormal, ['academic_year_id', 'term_id', 'program_id', 'section_id', 'date_from', 'date_until', 'actor_id']),
            self::SchedulingException => $this->definitionRow('Scheduling Exception Report', 'Scheduling demands with source-backed readiness findings requiring action.', self::SensitivitySensitive, ['academic_year_id', 'term_id', 'program_id', 'section_id', 'status', 'date_from', 'date_until']),
            self::FacultyLoadOverride => $this->definitionRow('Faculty Term Load Override Report', 'Approved term load overrides and recorded authority.', self::SensitivitySensitive, ['academic_year_id', 'term_id', 'status', 'date_from', 'date_until', 'actor_id']),
            self::ProgressionException => $this->definitionRow('Academic Progression Exception Report', 'Scoped prerequisite, corequisite, bridging, and conflict exceptions.', self::SensitivityStudentData, $studentFilters),
            self::GradeCorrection => $this->definitionRow('Grade Correction Audit Log', 'Append-only approved posted-grade correction events.', self::SensitivityStudentData, $studentFilters),
            self::PendingGrade => $this->definitionRow('Pending Grade List', 'Released Pending Grade outcomes requiring resolution.', self::SensitivityStudentData, $studentFilters),
            self::IncCompletion => $this->definitionRow('INC Completion / Removal List', 'Current INC outcomes and preserved INC resolution history.', self::SensitivityStudentData, $studentFilters),
            self::LateGradeAuthorization => $this->definitionRow('Late Grade Encoding Authorization List', 'Scoped late grade windows and approving authority.', self::SensitivitySensitive, ['academic_year_id', 'term_id', 'section_id', 'status', 'date_from', 'date_until', 'actor_id']),
            self::UnitLoadException => $this->definitionRow('Student Unit Load Exception List', 'Approved unit-load exceptions from the consolidated enrollment exception source.', self::SensitivityStudentData, $studentFilters),
            self::UserRole => $this->definitionRow('User and Role Report', 'Application identities, canonical roles, and account status.', self::SensitivitySensitive, ['status', 'date_from', 'date_until']),
            self::ActivityLog => $this->definitionRow('Activity / Audit Log', 'Read-only high-risk application activity from the existing activity-log source.', self::SensitivitySensitive, ['date_from', 'date_until', 'actor_id']),
            self::GeneratedOutput => $this->definitionRow('Generated Output Access Audit', 'Official output view, print, download, and export evidence.', self::SensitivitySensitive, ['date_from', 'date_until', 'actor_id', 'output_type', 'sensitivity', 'source_record_id']),
            self::ReportExport => $this->definitionRow('Report Export Audit', 'REPORT / EXPORT evidence recorded by this fixed report catalog.', self::SensitivitySensitive, ['date_from', 'date_until', 'actor_id', 'sensitivity', 'source_record_id']),
            self::IntegrationEvent => $this->definitionRow('Integration Event Log', 'Typed integration outcomes without raw payloads, secrets, or internal diagnostics.', self::SensitivitySensitive, ['date_from', 'date_until', 'actor_id']),
        ];
    }

    /** @return array{label:string,description:string,sensitivity:string,filters:list<string>} */
    private function definition(string $reportKey): array
    {
        return $this->catalog()[$reportKey] ?? throw new \InvalidArgumentException("Unknown report [{$reportKey}].");
    }

    /** @param list<string> $filters @return array{label:string,description:string,sensitivity:string,filters:list<string>} */
    private function definitionRow(string $label, string $description, string $sensitivity, array $filters): array
    {
        return compact('label', 'description', 'sensitivity', 'filters');
    }

    /** @return array{key:string,label:string,path?:string,format?:string,badge?:bool,resolver?:string} */
    private function column(string $key, string $label, ?string $path = null, string $format = 'text', bool $badge = false, ?string $resolver = null): array
    {
        return array_filter(compact('key', 'label', 'path', 'format', 'badge', 'resolver'), fn (mixed $value): bool => $value !== null);
    }

    /** @return list<array{key:string,label:string,path?:string,format?:string,badge?:bool,resolver?:string}> */
    private function outputAuditColumns(bool $includeFilterSummary = false): array
    {
        $columns = [
            $this->column('occurred_at', 'Occurred At', 'occurred_at', 'datetime'),
            $this->column('actor', 'Actor', 'actor.name'),
            $this->column('actor_role', 'Actor Role', 'actor_role'),
            $this->column('output_type', 'Output Type', 'output_type', badge: true),
            $this->column('action', 'Action', 'action', badge: true),
            $this->column('source', 'Source Record', resolver: 'output_source'),
            $this->column('row_count', 'Row Count', 'row_count'),
            $this->column('sensitivity', 'Sensitivity', 'sensitivity', badge: true),
            $this->column('purpose', 'Purpose', 'purpose'),
            $this->column('status', 'Status', 'status', badge: true),
        ];

        if ($includeFilterSummary) {
            $columns[] = $this->column('filter_summary', 'Filter Summary', 'filter_summary', 'json');
        }

        return $columns;
    }

    /** @return Builder<LedgerEntry> */
    private function termFeeSummaryQuery(): Builder
    {
        $ledger = (new LedgerEntry)->getTable();
        $profiles = (new StudentProfile)->getTable();
        $programs = (new Program)->getTable();
        $terms = (new Term)->getTable();

        return LedgerEntry::query()
            ->join($profiles, "{$profiles}.id", '=', "{$ledger}.student_profile_id")
            ->leftJoin($programs, "{$programs}.id", '=', "{$profiles}.program_id")
            ->leftJoin($terms, "{$terms}.id", '=', "{$ledger}.term_id")
            ->where("{$ledger}.direction", LedgerEntry::DirectionPayment)
            ->select(["{$ledger}.term_id", "{$profiles}.program_id", "{$ledger}.category"])
            ->selectRaw("{$terms}.label as term_label")
            ->selectRaw("{$programs}.name as program_name")
            ->selectRaw("COUNT({$ledger}.id) as entry_count")
            ->selectRaw("SUM(ABS({$ledger}.amount)) as total_collected")
            ->groupBy("{$ledger}.term_id", "{$profiles}.program_id", "{$ledger}.category", "{$terms}.label", "{$programs}.name")
            ->orderByDesc("{$ledger}.term_id");
    }

    /** @param Builder<Model> $query */
    private function applyRelationFilter(Builder $query, ?string $relation, string $column, mixed $value): void
    {
        if ($relation === null || blank($value)) {
            return;
        }

        $query->whereHas($relation, fn (Builder $relationQuery) => $relationQuery->where($column, (int) $value));
    }

    /** @return array<string, string> */
    private function termRelations(): array
    {
        return [
            self::EnrollmentMaster => 'term', self::CapacityPending => 'term',
            self::SectionCapacity => 'termOffering.term', self::LifecycleRegister => 'term',
            self::GraduationBatch => 'term', self::GraduationSnapshot => 'member.batch.term',
            self::DailyCash => 'term', self::PendingOrMapping => 'term', self::StudentLedger => 'term',
            self::FinancialAccommodation => 'term', self::FacultyLoad => 'schedulingDemand.termOffering.term',
            self::SchedulingException => 'termOffering.term', self::FacultyLoadOverride => 'term',
            self::ProgressionException => 'term', self::GradeCorrection => 'row.roster.termOffering.term',
            self::PendingGrade => 'roster.termOffering.term', self::IncCompletion => 'roster.termOffering.term',
            self::LateGradeAuthorization => 'termOffering.term', self::UnitLoadException => 'term',
        ];
    }

    /** @return array<string, string> */
    private function academicYearRelations(): array
    {
        return [
            self::EnrollmentMaster => 'term.academicYear', self::CapacityPending => 'term.academicYear',
            self::SectionCapacity => 'termOffering.term.academicYear', self::LifecycleRegister => 'term.academicYear',
            self::GraduationBatch => 'academicYear', self::GraduationSnapshot => 'member.batch.academicYear',
            self::FacultyLoad => 'schedulingDemand.termOffering.term.academicYear',
            self::SchedulingException => 'termOffering.term.academicYear', self::FacultyLoadOverride => 'term.academicYear',
            self::ProgressionException => 'term.academicYear', self::GradeCorrection => 'row.roster.termOffering.term.academicYear',
            self::PendingGrade => 'roster.termOffering.term.academicYear', self::IncCompletion => 'roster.termOffering.term.academicYear',
            self::LateGradeAuthorization => 'termOffering.term.academicYear', self::UnitLoadException => 'term.academicYear',
        ];
    }

    /** @return array<string, string> */
    private function programRelations(): array
    {
        return [
            self::EnrollmentMaster => 'studentProfile.program', self::CapacityPending => 'studentProfile.program',
            self::SectionCapacity => 'termOffering.curriculumEntry.curriculumVersion.program',
            self::LifecycleRegister => 'studentProfile.program', self::GraduationSnapshot => 'member.studentProfile.program',
            self::DailyCash => 'studentProfile.program', self::PendingOrMapping => 'studentProfile.program',
            self::StudentLedger => 'studentProfile.program', self::FinancialAccommodation => 'studentProfile.program',
            self::FacultyLoad => 'schedulingDemand.termOffering.curriculumEntry.curriculumVersion.program',
            self::SchedulingException => 'termOffering.curriculumEntry.curriculumVersion.program',
            self::ProgressionException => 'studentProfile.program', self::GradeCorrection => 'row.courseEnrollment.enrollment.studentProfile.program',
            self::PendingGrade => 'courseEnrollment.enrollment.studentProfile.program',
            self::IncCompletion => 'courseEnrollment.enrollment.studentProfile.program',
            self::UnitLoadException => 'studentProfile.program',
        ];
    }

    /** @return array<string, string> */
    private function sectionRelations(): array
    {
        return [
            self::FacultyLoad => 'schedulingDemand.sectionDeliveryGroup.section',
            self::SchedulingException => 'sectionDeliveryGroup.section',
            self::GradeCorrection => 'row.roster.section', self::PendingGrade => 'roster.section',
            self::IncCompletion => 'roster.section', self::LateGradeAuthorization => 'roster.section',
        ];
    }

    /** @return array<string, string> */
    private function statusColumns(): array
    {
        return [
            self::EnrollmentMaster => 'status', self::CapacityPending => 'status', self::SectionCapacity => 'state',
            self::LifecycleRegister => 'state', self::GraduationBatch => 'state', self::GraduationSnapshot => 'result_status',
            self::PendingOrMapping => 'evidence_status', self::FinancialAccommodation => 'status',
            self::SchedulingException => 'validation_state', self::FacultyLoadOverride => 'is_active',
            self::ProgressionException => 'state', self::PendingGrade => 'current_outcome_category',
            self::IncCompletion => 'current_outcome_category', self::LateGradeAuthorization => 'state',
            self::UnitLoadException => 'state', self::UserRole => 'status',
        ];
    }

    /** @return array<string, string> */
    private function dateColumns(): array
    {
        return [
            self::EnrollmentMaster => 'officially_enrolled_at', self::CapacityPending => 'created_at',
            self::LifecycleRegister => 'effective_on', self::GraduationBatch => 'created_at',
            self::GraduationSnapshot => 'generated_at', self::DailyCash => 'posted_at',
            self::PendingOrMapping => 'paid_at', self::StudentLedger => 'posted_at', self::TermFeeSummary => 'posted_at',
            self::FinancialAccommodation => 'created_at', self::FacultyLoad => 'published_at',
            self::SchedulingException => 'readiness_checked_at', self::FacultyLoadOverride => 'created_at',
            self::ProgressionException => 'approved_at', self::GradeCorrection => 'created_at',
            self::PendingGrade => 'released_at', self::IncCompletion => 'released_at',
            self::LateGradeAuthorization => 'opens_at', self::UnitLoadException => 'approved_at',
            self::UserRole => 'created_at', self::ActivityLog => 'created_at', self::GeneratedOutput => 'occurred_at',
            self::ReportExport => 'occurred_at', self::IntegrationEvent => 'occurred_at',
        ];
    }

    /** @return array<string, string> */
    private function actorColumns(): array
    {
        return [
            self::LifecycleRegister => 'recorded_by', self::GraduationBatch => 'created_by',
            self::GraduationSnapshot => 'generated_by', self::DailyCash => 'posted_by',
            self::StudentLedger => 'posted_by', self::FinancialAccommodation => 'recorded_by',
            self::FacultyLoad => 'faculty_user_id', self::FacultyLoadOverride => 'faculty_user_id',
            self::GradeCorrection => 'recorded_by', self::LateGradeAuthorization => 'approved_by',
            self::ActivityLog => 'causer_id', self::GeneratedOutput => 'actor_user_id',
            self::ReportExport => 'actor_user_id', self::IntegrationEvent => 'user_id',
        ];
    }

    /** @return array<string, string> */
    private function studentColumns(): array
    {
        return [
            self::EnrollmentMaster => 'student_profile_id', self::CapacityPending => 'student_profile_id',
            self::LifecycleRegister => 'student_profile_id', self::DailyCash => 'student_profile_id',
            self::PendingOrMapping => 'student_profile_id', self::StudentLedger => 'student_profile_id',
            self::FinancialAccommodation => 'student_profile_id', self::ProgressionException => 'student_profile_id',
            self::UnitLoadException => 'student_profile_id',
        ];
    }

    /** @return array<string, string> */
    private function studentRelations(): array
    {
        return [
            self::GraduationSnapshot => 'member.studentProfile',
        ];
    }

    private function statusValue(string $reportKey, string $value): string|int
    {
        if ($reportKey === self::FacultyLoadOverride) {
            return (int) $value;
        }

        return $value;
    }

    private function studentName(Model $record): string
    {
        $profile = data_get($record, 'studentProfile')
            ?? data_get($record, 'courseEnrollment.enrollment.studentProfile');

        return $this->nameFromProfile($profile);
    }

    private function nameFromProfile(mixed $profile): string
    {
        if (! $profile instanceof StudentProfile) {
            return '';
        }

        return collect([$profile->first_name, $profile->middle_name, $profile->last_name])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' ');
    }

    private function accommodationEffects(Model $record): string
    {
        return collect([
            'Current-term finance gate' => $record->getAttribute('allows_finance_gate'),
            'Next-term enrollment' => $record->getAttribute('allows_next_term_enrollment'),
            'Reactivation' => $record->getAttribute('allows_reactivation'),
            'Record release' => $record->getAttribute('allows_record_release'),
            'Downpayment waived' => $record->getAttribute('waives_downpayment'),
        ])->filter()->keys()->implode('; ');
    }

    private function formatValue(mixed $value, string $format): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof CarbonInterface) {
            return $value->format($format === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        return match ($format) {
            'money' => number_format((float) $value, 2, '.', ''),
            'boolean' => (bool) $value ? 'Yes' : 'No',
            'json' => is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $value,
            default => Str::of((string) $value)->replace('_', ' ')->toString(),
        };
    }
}
