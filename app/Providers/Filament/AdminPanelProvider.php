<?php

namespace App\Providers\Filament;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Clusters\PublicContent;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\AcademicReadiness;
use App\Filament\Pages\AssistedAdmissionApplication;
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
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
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
use App\Models\FaqEntry;
use App\Models\User;
use App\Support\TalaPanelTheme;
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
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return TalaPanelTheme::configure($panel)
            ->default()
            ->id('admin')
            ->path('admin')
            ->homeUrl(function (): string {
                $user = auth()->user();
                $contexts = app(WorkspaceContextResolver::class);

                return $user instanceof User
                    ? ($contexts->destinationFor($user, $contexts->selected($user)) ?? route('workspace-chooser'))
                    : route('filament.admin.auth.login');
            })
            ->login(ContextualLogin::class)
            ->passwordReset()
            ->emailVerification()
            ->emailChangeVerification()
            ->profile(AccountSecurity::class, isSimple: false)
            ->multiFactorAuthentication(
                TalaAppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffMfaIsEnabled::class)
            ->brandName('TALA Staff Workspace')
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(fn (AuthPageConfig $config) => $config
                        ->media(is_file(public_path('images/auth/admin.webp')) ? asset('images/auth/admin.webp') : null, alt: '')
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
                PublicNoticeResource::class,
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
                AssistedAdmissionApplication::class,
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
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->authenticatedRoutes(function (): void {
                Route::get('faq-entries', function (): RedirectResponse {
                    Gate::authorize('viewAny', FaqEntry::class);

                    return redirect(FaqEntryResource::getUrl());
                })->name('legacy-faq.index');
                Route::get('faq-entries/create', function (): RedirectResponse {
                    Gate::authorize('create', FaqEntry::class);

                    return redirect(FaqEntryResource::getUrl('create'));
                })->name('legacy-faq.create');
                Route::get('faq-entries/{record}/edit', function (string $record): RedirectResponse {
                    Gate::authorize('viewAny', FaqEntry::class);
                    $faq = FaqEntry::query()->findOrFail($record);
                    Gate::authorize('update', $faq);

                    return redirect(FaqEntryResource::getUrl('edit', ['record' => $faq]));
                })->whereNumber('record')->name('legacy-faq.edit');
            })
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
                'Admissions' => AdmissionApplicationResource::class,
                'Catalog & Curricula' => CatalogCurriculaWorkbench::class,
                'Term Planning' => TermPlanningWorkbench::class,
                'Students & Enrollment' => EnrollmentResource::class,
                'Grades & Completion' => GradesAndCompletion::class,
            ],
            User::StaffRoleAccounting => [
                'Fee Plans' => FeePlanResource::class,
                'Student Accounts' => EnrollmentResource::class,
            ],
            User::StaffRoleFaculty => [
                'My Availability' => CalendarEventResource::class,
                'My Schedule' => FacultySchedule::class,
                'Grade Rosters' => FacultyGradeRoster::class,
            ],
            User::StaffRoleAcademicHead => [
                'Academic Oversight' => AcademicApprovals::class,
            ],
            User::StaffRoleSystemSuperAdmin => [
                'Users & Access' => UserResource::class,
                'Public Content' => PublicContent::class,
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
