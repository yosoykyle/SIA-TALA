<?php

namespace App\Filament\Student\Pages;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\Enrollment\RegistrationEntryContext;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\StudentHub\StudentHubPriorityResolver;
use App\Actions\StudentHub\StudentStudyContext;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\Enrollment as EnrollmentRecord;
use App\Models\Hold;
use App\Models\PriorAcademicCredit;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\RegistrationSchedulePresentation;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Overview';

    protected static ?string $navigationLabel = 'Overview';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.student.pages.enrollment-summary')->viewData(function (): array {
                $user = auth()->user();
                abort_unless($user instanceof User && $user->hasRole('student'), 403);
                $context = app(RegistrationEntryContext::class)->forUser($user);
                $activeOfficialEnrollments = app(CurrentOfficialEnrollmentResolver::class)->allActiveForStudent($user);
                $currentOfficialEnrollment = $activeOfficialEnrollments->first();
                $case = $context['enrollment'] ?? $currentOfficialEnrollment;
                $case?->loadMissing(['termAccount', 'currentProposalVersion.confirmation', 'term']);
                $notice = $user->studentProfile !== null ? app(StudentHubPriorityResolver::class)->resolve($user->studentProfile) : null;
                $scheduleCase = $case?->canonical_outcome === EnrollmentRecord::OutcomeOfficiallyEnrolled
                    ? $case
                    : $currentOfficialEnrollment;
                $externallyArrangedClasses = $scheduleCase instanceof EnrollmentRecord
                    ? CourseEnrollment::query()
                        ->with(['termOffering.curriculumEntry.courseSpecification.course', 'section'])
                        ->where('enrollment_id', $scheduleCase->id)
                        ->where('status', CourseEnrollment::StatusActive)
                        ->where('is_current', true)
                        ->whereHas('termOffering.curriculumEntry.courseSpecification', fn ($query) => $query
                            ->where('scheduling_treatment', CourseSpecification::SchedulingExternallyArranged))
                        ->get()
                        ->map(function (CourseEnrollment $registration): array {
                            $spec = $registration->termOffering?->curriculumEntry?->courseSpecification;

                            return [
                                'course_code' => (string) ($spec?->course?->code ?? 'Subject'),
                                'course_title' => (string) ($spec?->title ?? 'Externally arranged subject'),
                                'section_code' => (string) ($registration->section?->code ?: 'Not sectioned'),
                            ];
                        })
                        ->sortBy('course_code')
                        ->values()
                        ->all()
                    : [];

                $activeHolds = $user->studentProfile?->holds()
                    ->where('status', Hold::StatusActive)
                    ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->get() ?? collect();

                return [
                    'academicSnapshot' => $this->academicSnapshot($user->studentProfile, $case),
                    'priorCreditsSummary' => $this->priorCreditsSummary($user->studentProfile),
                    'context' => $context,
                    'case' => $case,
                    'studyContext' => app(StudentStudyContext::class)->forStudent($user),
                    'requirement' => $case !== null ? app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case) : null,
                    'position' => $case?->termAccount !== null ? app(TermAccountProjection::class)->forAccount($case->termAccount) : null,
                    'schedulePresentation' => $case?->canonical_outcome === EnrollmentRecord::OutcomeOfficiallyEnrolled
                        ? app(RegistrationSchedulePresentation::class)->currentForEnrollment($case) : null,
                    'recentScheduleRevisions' => ScheduleView::recentScheduleRevisionsForEnrollment($scheduleCase),
                    'externallyArrangedClasses' => $externallyArrangedClasses,
                    'activeOfficialTermCount' => $activeOfficialEnrollments->count(),
                    'notice' => in_array($notice['tier'] ?? null, ['Schedule Available', 'COR Available'], true) ? null : $notice,
                    'latestEvidence' => $case?->termAccount?->paymentEvidenceVersions()->latest('version')->first(),
                    'activeHolds' => $activeHolds,
                    'activeFinancialHolds' => $activeHolds->where('hold_type', Hold::TypeFinancial)->values(),
                ];
            }),
        ]);
    }

    /**
     * Read-only counts for the Overview result and study plan tiles, from the same projections as Academics.
     *
     * @return array{subjects:int, released:int, incomplete:int, completed_units:float, total_units:float, plan:array{done:int, active:int, attention:int, later:int}}|null
     */
    private function academicSnapshot(?StudentProfile $student, ?EnrollmentRecord $case): ?array
    {
        if (! $student instanceof StudentProfile) {
            return null;
        }

        $current = $case?->canonical_outcome === EnrollmentRecord::OutcomeOfficiallyEnrolled
            ? app(OfficialCourseResultProjection::class)->forStudent($student)->filter(fn (array $result): bool => ($result['course_enrollment'] ?? null) instanceof CourseEnrollment
                && (int) $result['course_enrollment']->enrollment_id === (int) $case->id
                && $result['course_enrollment']->is_current
                && $result['course_enrollment']->status === CourseEnrollment::StatusActive)
            : collect();
        $curriculum = app(CurriculumEvaluation::class)->forStudent($student);
        $rows = collect($curriculum['required']);

        return [
            'subjects' => $current->count(),
            'released' => $current->whereNotNull('outcome_source')->count(),
            'incomplete' => $current->where('result', 'INC')->count(),
            'completed_units' => (float) $curriculum['completed_units'],
            'total_units' => (float) $rows->sum(fn (array $row): float => (float) $row['curriculum_entry']->courseSpecification?->credit_units),
            'plan' => [
                'done' => $rows->whereIn('status', ['Completed', 'Approved credit'])->count(),
                'active' => $rows->where('status', 'In progress')->count(),
                'attention' => $rows->where('status', 'Deficient')->count(),
                'later' => $rows->where('status', 'Not taken')->count(),
            ],
        ];
    }

    /**
     * Summary of active prior academic credits for continuing/migrated student onboarding surfaces.
     *
     * @return array{count:int, passed_count:int, units:float, inc_count:int, failed_count:int, external_transfer_count:int}
     */
    private function priorCreditsSummary(?StudentProfile $student): array
    {
        if (! $student instanceof StudentProfile) {
            return [
                'count' => 0,
                'passed_count' => 0,
                'units' => 0.0,
                'inc_count' => 0,
                'failed_count' => 0,
                'external_transfer_count' => 0,
            ];
        }

        $credits = PriorAcademicCredit::query()
            ->active()
            ->where('student_profile_id', $student->id)
            ->get();

        $passing = $credits->filter(fn (PriorAcademicCredit $credit): bool => $credit->isPassing());

        return [
            'count' => $credits->count(),
            'passed_count' => $passing->count(),
            'units' => (float) $passing->sum(fn (PriorAcademicCredit $credit): float => (float) $credit->units),
            'inc_count' => $credits->filter(fn (PriorAcademicCredit $credit): bool => $credit->effectiveResultCode() === 'INC')->count(),
            'failed_count' => $credits->filter(fn (PriorAcademicCredit $credit): bool => $credit->result_status === PriorAcademicCredit::ResultFailed
                || (is_numeric($credit->effectiveResultCode()) && (float) $credit->effectiveResultCode() > 4.00))->count(),
            'external_transfer_count' => $credits->filter(fn (PriorAcademicCredit $credit): bool => CurriculumEvaluation::isExternalTransferPriorCredit($credit))->count(),
        ];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [];
    }
}
