<?php

namespace App\Providers\Filament;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\AcademicReadiness;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\ClassPlanning;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Pages\SystemHealth;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Resources\AcademicCalendarWindows\AcademicCalendarWindowResource;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Filament\Resources\Terms\TermResource;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\StaffRoleWorkspaceOverviewWidget;
use App\Http\Middleware\EnforceCanonicalSessionPolicy;
use App\Http\Middleware\EnsureStaffMfaIsEnabled;
use App\Models\User;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Caresome\FilamentAuthDesigner\Enums\MediaPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(ContextualLogin::class)
            ->passwordReset()
            ->emailVerification()
            ->emailChangeVerification()
            ->profile(AccountSecurity::class)
            ->multiFactorAuthentication(
                TalaAppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffMfaIsEnabled::class)
            ->brandName('TALA Staff Workspace')
            ->brandLogo(asset('talalogo.png'))
            ->colors([
                'primary' => Color::Blue,
            ])
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(fn (AuthPageConfig $config) => $config
                        ->media(asset('storage/images/admin-bg.png'))
                        ->mediaPosition(MediaPosition::Left)
                        ->mediaSize('50%')
                    )
                    ->login(fn (AuthPageConfig $config) => $config
                        ->usingPage(ContextualLogin::class)
                    )
                    ->passwordReset()
                    ->emailVerification()
                    ->themeToggle()
            )
            ->resources([
                UserResource::class,
                FaqEntryResource::class,
                AdmissionApplicationResource::class,
                AdmissionCycleResource::class,
                EnrollmentResource::class,
                FeePlanResource::class,
                ProgramResource::class,
                CourseResource::class,
                CourseSpecificationResource::class,
                CurriculumVersionResource::class,
                ImportBatchResource::class,
                AcademicYearResource::class,
                TermResource::class,
                AcademicCalendarWindowResource::class,
                CalendarEventResource::class,
                RoomResource::class,
                FacultyQualificationResource::class,
                FacultyTermLoadOverrideResource::class,
                TermOfferingResource::class,
                SectionResource::class,
                GradeRosterResource::class,
                TranscriptRequestResource::class,
                SchedulingDemandResource::class,
                ScheduleGenerationRunResource::class,
                SectionMeetingResource::class,
                StudentProfileResource::class,
                StudentLifecycleChangeResource::class,
                DuplicateProfileResolutionResource::class,
            ])
            ->pages([
                Dashboard::class,
                CatalogCurriculaWorkbench::class,
                TermPlanningWorkbench::class,
                AcademicApprovals::class,
                AcademicReadiness::class,
                ClassPlanning::class,
                FacultyGradeRoster::class,
                FacultySchedule::class,
                GradesAndCompletion::class,
                CompletionAndTor::class,
                SystemHealth::class,
                GovernanceAudit::class,
            ])
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => $this->staffNavigation($builder))
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                StaffRoleWorkspaceOverviewWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnforceCanonicalSessionPolicy::class,
            ]);
    }

    private function staffNavigation(NavigationBuilder $builder): NavigationBuilder
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $builder;
        }

        $contextResolver = app(WorkspaceContextResolver::class);
        $selectedContext = $contextResolver->selected($user);

        if ($selectedContext === null) {
            $available = $contextResolver->availableContexts($user);
            $selectedContext = count($available) === 1 ? array_key_first($available) : null;
        }

        $components = match ($selectedContext) {
            User::StaffRoleRegistrar => [
                'Home' => Dashboard::class,
                'Admissions' => AdmissionApplicationResource::class,
                'Admission Cycles' => AdmissionCycleResource::class,
                'Catalog & Curricula' => CatalogCurriculaWorkbench::class,
                'Term Planning' => TermPlanningWorkbench::class,
                'Students & Enrollment' => EnrollmentResource::class,
                'Grades & Completion' => GradesAndCompletion::class,
            ],
            User::StaffRoleAccounting => [
                'Home' => Dashboard::class,
                'Fee Plans' => FeePlanResource::class,
                'Student Accounts' => EnrollmentResource::class,
            ],
            User::StaffRoleFaculty => [
                'Home' => Dashboard::class,
                'My Schedule' => FacultySchedule::class,
                'Grade Rosters' => FacultyGradeRoster::class,
                'My Unavailable Times' => CalendarEventResource::class,
            ],
            User::StaffRoleAcademicHead => [
                'Home' => Dashboard::class,
                'Catalog & Curricula' => CatalogCurriculaWorkbench::class,
                'Term Planning' => TermPlanningWorkbench::class,
                'Approvals' => AcademicApprovals::class,
            ],
            User::StaffRoleSystemSuperAdmin => [
                'Home' => Dashboard::class,
                'Users & Access' => UserResource::class,
                'Public Content' => FaqEntryResource::class,
                'System Health' => SystemHealth::class,
                'Governance & Audit' => GovernanceAudit::class,
            ],
            default => [],
        };

        return $builder->items($this->labeledNavigationItems($components));
    }

    /**
     * @param  array<string, class-string>  $components
     * @return list<NavigationItem>
     */
    private function labeledNavigationItems(array $components): array
    {
        return collect($components)
            ->flatMap(function (string $component, string $label): array {
                if (! $component::canAccess()) {
                    return [];
                }

                return collect($component::getNavigationItems())
                    ->map(fn (NavigationItem $item): NavigationItem => $item->label($label))
                    ->all();
            })
            ->values()
            ->all();
    }
}
