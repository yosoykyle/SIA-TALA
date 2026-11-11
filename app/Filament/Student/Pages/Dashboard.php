<?php

namespace App\Filament\Student\Pages;

use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Academics\OfficialCourseResultProjection;
use App\Actions\Enrollment\RegistrationEntryContext;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\StudentHub\StudentHubPriorityResolver;
use App\Actions\StudentHub\StudentStudyContext;
use App\Models\CourseEnrollment;
use App\Models\Enrollment as EnrollmentRecord;
use App\Models\Hold;
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
                $case = $context['enrollment'];
                $case?->loadMissing(['termAccount', 'currentProposalVersion.confirmation', 'term']);
                $notice = $user->studentProfile !== null ? app(StudentHubPriorityResolver::class)->resolve($user->studentProfile) : null;

                return [
                    'academicSnapshot' => $this->academicSnapshot($user->studentProfile, $case),
                    'context' => $context,
                    'case' => $case,
                    'studyContext' => app(StudentStudyContext::class)->forStudent($user),
                    'requirement' => $case !== null ? app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case) : null,
                    'position' => $case?->termAccount !== null ? app(TermAccountProjection::class)->forAccount($case->termAccount) : null,
                    'schedulePresentation' => $case?->canonical_outcome === EnrollmentRecord::OutcomeOfficiallyEnrolled
                        ? app(RegistrationSchedulePresentation::class)->currentFor($user) : null,
                    'notice' => in_array($notice['tier'] ?? null, ['Schedule Available', 'COR Available'], true) ? null : $notice,
                    'latestEvidence' => $case?->termAccount?->paymentEvidenceVersions()->latest('version')->first(),
                    'activeHolds' => $user->studentProfile?->holds()
                        ->where('status', Hold::StatusActive)
                        ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->get() ?? collect(),
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
            ? app(OfficialCourseResultProjection::class)->forStudent($student)->filter(fn (array $result): bool => (int) $result['course_enrollment']->enrollment_id === (int) $case->id
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
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [];
    }
}
