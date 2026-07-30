<?php

namespace App\Filament\Widgets;

use App\Actions\SystemAdministration\IntegrationHealthPresenter;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\AcademicReadiness;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\IntegrationStatus;
use App\Filament\Pages\PayMongoReconciliation;
use App\Filament\Pages\ReportsAudit;
use App\Filament\Resources\Assessments\AssessmentResource;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\FeeRules\FeeRuleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Assessment;
use App\Models\CalendarEvent;
use App\Models\FaqEntry;
use App\Models\GradeRoster;
use App\Models\SectionMeeting;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StaffRoleWorkspaceOverviewWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole([
            User::StaffRoleAccounting,
            User::StaffRoleFaculty,
            User::StaffRoleAcademicHead,
            User::StaffRoleSystemSuperAdmin,
        ]) ?? false;
    }

    protected function getHeading(): ?string
    {
        return match (true) {
            $this->actor()->hasRole(User::StaffRoleAccounting) => 'Accounting Work',
            $this->actor()->hasRole(User::StaffRoleFaculty) => 'My Faculty Work',
            $this->actor()->hasRole(User::StaffRoleAcademicHead) => 'Academic Oversight',
            default => 'System Administration',
        };
    }

    protected function getDescription(): ?string
    {
        return match (true) {
            $this->actor()->hasRole(User::StaffRoleAccounting) => 'Start with fee rules, then review student accounts and payment exceptions.',
            $this->actor()->hasRole(User::StaffRoleFaculty) => 'Your schedule, unavailable times, and assigned grade rosters are kept together here.',
            $this->actor()->hasRole(User::StaffRoleAcademicHead) => 'Use these read and review surfaces for academic readiness, planning, grades, and reports.',
            default => 'Manage identities and public content, then inspect governed settings, integrations, and audit evidence.',
        };
    }

    protected function getColumns(): int|array|null
    {
        if (count($this->getCachedStats()) === 4) {
            return ['@xl' => 2, '!@lg' => 2];
        }

        return parent::getColumns();
    }

    protected function getStats(): array
    {
        return match (true) {
            $this->actor()->hasRole(User::StaffRoleAccounting) => $this->accountingStats(),
            $this->actor()->hasRole(User::StaffRoleFaculty) => $this->facultyStats(),
            $this->actor()->hasRole(User::StaffRoleAcademicHead) => $this->academicHeadStats(),
            default => $this->systemAdministrationStats(),
        };
    }

    /**
     * @return list<Stat>
     */
    private function accountingStats(): array
    {
        $activeAssessments = Assessment::query()
            ->where('state', Assessment::StateActive)
            ->count();

        return [
            $this->stat('1. Fee Setup', 'Configure charges', 'Review effective fee rules before assessment.', FeeRuleResource::getUrl('index')),
            $this->stat('2. Student Accounts', "{$activeAssessments} active", 'Assess obligations and inspect the account ledger.', AssessmentResource::getUrl('index')),
            $this->stat('3. Payment Exceptions', 'Review queue', 'Resolve failed or recovered evidence without bypassing verification.', PayMongoReconciliation::getUrl()),
            $this->stat('4. Reports', 'Finance evidence', 'Open authorized fixed reports and audited exports.', ReportsAudit::getUrl()),
        ];
    }

    /**
     * @return list<Stat>
     */
    private function facultyStats(): array
    {
        $actor = $this->actor();
        $meetings = SectionMeeting::query()
            ->activeOfficial()
            ->where('faculty_user_id', $actor->id)
            ->count();
        $activeRosters = GradeRoster::query()
            ->where('faculty_user_id', $actor->id)
            ->whereIn('state', [
                GradeRoster::StateDraft,
                GradeRoster::StateReturned,
                GradeRoster::StateLateNotSubmitted,
            ])
            ->count();
        $unavailableBlocks = CalendarEvent::query()
            ->recurringSchedulingBlocks()
            ->where('scope_type', CalendarEvent::ScopeFaculty)
            ->where('faculty_user_id', $actor->id)
            ->count();

        return [
            $this->stat('1. My Schedule', "{$meetings} published meetings", 'View only your official teaching assignments.', FacultySchedule::getUrl()),
            $this->stat('2. Grade Rosters', "{$activeRosters} need work", 'Encode assigned rosters and review submission history.', FacultyGradeRoster::getUrl()),
            $this->stat('3. My Unavailable Times', "{$unavailableBlocks} recorded", 'Record recurring times when you cannot be assigned.', CalendarEventResource::getUrl('index')),
        ];
    }

    /**
     * @return list<Stat>
     */
    private function academicHeadStats(): array
    {
        $submittedRosters = GradeRoster::query()
            ->where('state', GradeRoster::StateSubmitted)
            ->count();

        return [
            $this->stat('1. Academic Oversight', 'Review readiness', 'Inspect program, curriculum, and planning readiness without changing office ownership.', AcademicReadiness::getUrl()),
            $this->stat('2. Approvals', "{$submittedRosters} grade rosters submitted", 'Open only the academic decisions assigned to your role.', AcademicApprovals::getUrl()),
            $this->stat('3. Reports', 'Academic evidence', 'Open the fixed reports authorized for Academic Head review.', ReportsAudit::getUrl()),
        ];
    }

    /**
     * @return list<Stat>
     */
    private function systemAdministrationStats(): array
    {
        $activeAccounts = User::query()->where('status', User::StatusActive)->count();
        $publishedFaqs = FaqEntry::query()->where('is_published', true)->count();
        $health = app(IntegrationHealthPresenter::class)->summary();

        return [
            $this->stat('1. Users & Access', "{$activeAccounts} active", 'Manage user identities and canonical role assignments.', UserResource::getUrl('index')),
            $this->stat('2. Public Content', "{$publishedFaqs} FAQs published", 'Curate the categorized guidance shown on the public site.', FaqEntryResource::getUrl('index')),
            $this->stat('3. System Health', $health['label'], $health['description'], IntegrationStatus::getUrl())
                ->color($health['color']),
            $this->stat('4. Governance & Audit', 'Read-only evidence', 'Review settings dispositions, reports, audit logs, and operational events.', ReportsAudit::getUrl()),
        ];
    }

    private function stat(string $label, string $value, string $description, string $url): Stat
    {
        return Stat::make($label, $value)
            ->description($description)
            ->descriptionIcon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->url($url);
    }

    private function actor(): User
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
