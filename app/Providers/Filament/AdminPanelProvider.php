<?php

namespace App\Providers\Filament;

use App\Actions\AcademicSetup\AcademicReadinessService;
use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Clusters\Admissions;
use App\Filament\Clusters\PublicContent;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\AssistedAdmissionApplication;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\PaymentChannels;
use App\Filament\Pages\StaffEntry;
use App\Filament\Pages\SystemHealth;
use App\Filament\Pages\TermCalendar;
use App\Filament\Pages\TermClasses;
use App\Filament\Pages\TermClassProposal;
use App\Filament\Pages\TermOverview;
use App\Filament\Pages\TermPlanningWorkbench;
use App\Filament\Pages\TermResources;
use App\Filament\Pages\TermTimetable;
use App\Filament\Resources\AcademicCalendarWindows\AcademicCalendarWindowResource;
use App\Filament\Resources\AcademicYears\AcademicYearResource;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionApplications\Pages\ListAdmissionApplications;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\DuplicateProfileResolutionResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Filament\Resources\FaqEntries\FaqEntryResource;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\GradeRosters\GradeRosterResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\PublicNotices\PublicNoticeResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Filament\Resources\Sections\Pages\CreateSection;
use App\Filament\Resources\Sections\Pages\EditSection;
use App\Filament\Resources\Sections\Pages\ListSections;
use App\Filament\Resources\Sections\Pages\ViewSection;
use App\Filament\Resources\Sections\SectionResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Filament\Resources\StudentProfiles\StudentProfileResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Filament\Resources\Terms\Pages\CreateTerm;
use App\Filament\Resources\Terms\Pages\EditTerm;
use App\Filament\Resources\Terms\Pages\ListTerms;
use App\Filament\Resources\Terms\Pages\ViewTerm;
use App\Filament\Resources\Terms\TermResource;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\EnforceCanonicalSessionPolicy;
use App\Http\Middleware\EnsureStaffMfaIsEnabled;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\FaqEntry;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\AdmissionQueueCounts;
use App\Support\NavigationBadgeCounts;
use App\Support\TalaPanelTheme;
use Caresome\FilamentAuthDesigner\AuthDesignerPlugin;
use Caresome\FilamentAuthDesigner\Data\AuthPageConfig;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
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
            ->emailVerification(EmailVerificationPrompt::class)
            ->emailChangeVerification()
            ->profile(AccountSecurity::class, isSimple: false)
            ->multiFactorAuthentication(
                TalaAppAuthentication::make()->recoverable(),
                isRequired: true,
            )
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureStaffMfaIsEnabled::class)
            ->brandName('Servitech Institute Asia — Staff Workspace')
            ->plugin(
                AuthDesignerPlugin::make()
                    ->defaults(TalaPanelTheme::authLayout(...))
                    ->login(fn (AuthPageConfig $config) => $config
                        ->usingPage(ContextualLogin::class)
                    )
                    ->passwordReset(fn (AuthPageConfig $config) => $config
                        ->usingPage(RequestPasswordReset::class)
                        ->usingResetPage(ResetPassword::class)
                    )
                    ->emailVerification(fn (AuthPageConfig $config) => $config
                        ->usingPage(EmailVerificationPrompt::class)
                    )
                    ->themeToggle()
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_PAGE_START,
                fn (array $scopes): View => view('filament.components.auth-back-link', [
                    'showBackToSignIn' => in_array(RequestPasswordReset::class, $scopes, true)
                        || in_array(ResetPassword::class, $scopes, true)
                        || request()->routeIs('filament.*.auth.password-reset.*', 'filament.applicant.auth.register'),
                ]),
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
                CurriculumVersionResource::class,
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
                StaffEntry::class,
                AssistedAdmissionApplication::class,
                CatalogCurriculaWorkbench::class,
                TermPlanningWorkbench::class,
                TermOverview::class,
                TermCalendar::class,
                TermClasses::class,
                TermClassProposal::class,
                TermResources::class,
                TermTimetable::class,
                AcademicApprovals::class,
                FacultyGradeRoster::class,
                MyAvailability::class,
                FacultySchedule::class,
                GradesAndCompletion::class,
                CompletionAndTor::class,
                SystemHealth::class,
                GovernanceAudit::class,
                PaymentChannels::class,
            ])
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->authenticatedRoutes(function (): void {
                foreach ([
                    'terms' => TermResource::class,
                    'term-offerings' => TermOfferingResource::class,
                    'sections' => SectionResource::class,
                    'scheduling-demands' => SchedulingDemandResource::class,
                    'rooms' => RoomResource::class,
                    'faculty-qualifications' => FacultyQualificationResource::class,
                    'faculty-term-load-overrides' => FacultyTermLoadOverrideResource::class,
                    'calendar-events' => CalendarEventResource::class,
                    'schedule-generation-runs' => ScheduleGenerationRunResource::class,
                    'section-meetings' => SectionMeetingResource::class,
                ] as $legacySlug => $resource) {
                    foreach (['index' => '', 'create' => '/create', 'view' => '/{record}', 'edit' => '/{record}/edit'] as $page => $suffix) {
                        if (! array_key_exists($page, $resource::getPages())) {
                            continue;
                        }
                        Route::get($legacySlug.$suffix, function (?string $record = null) use ($resource, $page): RedirectResponse {
                            abort_unless($resource::canAccess(), 403);

                            return redirect($resource::getUrl($page, [...request()->query(), ...($record ? ['record' => $record] : [])]));
                        })->whereNumber('record')->name('legacy-term-planning.'.$legacySlug.'.'.$page);
                    }
                }

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

                Route::get('admission-applications', function (): RedirectResponse {
                    Gate::authorize('viewAny', AdmissionApplication::class);

                    $query = request()->getQueryString();

                    return redirect(AdmissionApplicationResource::getUrl().($query ? '?'.$query : ''));
                })->name('resources.admission-applications.index');

                Route::get('admission-applications/{record}', function (string $record): RedirectResponse {
                    $application = AdmissionApplication::query()->findOrFail($record);
                    Gate::authorize('view', $application);

                    $query = request()->getQueryString();

                    return redirect(AdmissionApplicationResource::getUrl('view', ['record' => $application]).($query ? '?'.$query : ''));
                })->whereNumber('record')->name('resources.admission-applications.view');

                Route::get('admission-cycles', function (): RedirectResponse {
                    Gate::authorize('viewAny', AdmissionCycle::class);

                    $query = request()->getQueryString();

                    return redirect(AdmissionCycleResource::getUrl().($query ? '?'.$query : ''));
                })->name('resources.admission-cycles.index');

                Route::get('admission-cycles/create', function (): RedirectResponse {
                    Gate::authorize('create', AdmissionCycle::class);

                    return redirect(AdmissionCycleResource::getUrl('create'));
                })->name('resources.admission-cycles.create');

                Route::get('admission-cycles/{record}', function (string $record): RedirectResponse {
                    $cycle = AdmissionCycle::query()->findOrFail($record);
                    Gate::authorize('view', $cycle);

                    $query = request()->getQueryString();

                    return redirect(AdmissionCycleResource::getUrl('view', ['record' => $cycle]).($query ? '?'.$query : ''));
                })->whereNumber('record')->name('resources.admission-cycles.view');

                Route::get('admission-cycles/{record}/edit', function (string $record): RedirectResponse {
                    $cycle = AdmissionCycle::query()->findOrFail($record);
                    Gate::authorize('update', $cycle);

                    $query = request()->getQueryString();

                    return redirect(AdmissionCycleResource::getUrl('edit', ['record' => $cycle]).($query ? '?'.$query : ''));
                })->whereNumber('record')->name('resources.admission-cycles.edit');
            })
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder => $this->staffNavigation($builder))
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
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

        $badgeCounts = new NavigationBadgeCounts;
        $termPlanningContext = $this->focusedTermPlanningContext();
        if ($selectedContext === User::StaffRoleRegistrar) {
            $enrollmentItems = array_map(
                fn (NavigationItem $item): NavigationItem => $item
                    ->badge(fn (): ?string => ($count = $badgeCounts->registrarEnrollment()['total']) > 0 ? (string) $count : null, 'warning')
                    ->badgeTooltip(fn (): ?string => $badgeCounts->registrarEnrollmentTooltip()),
                $this->labeledNavigationItems(['Students & Enrollment' => EnrollmentResource::class]),
            );

            return $builder->group('Learner work', [
                ...$this->admissionsWithQueues($this->labeledNavigationItems(['Admissions' => Admissions::class]), $badgeCounts),
                ...$enrollmentItems,
                ...$this->gradesWithChildren($this->labeledNavigationItems(['Students Record' => GradesAndCompletion::class]), $badgeCounts),
            ])->group('Academic setup', $this->catalogWithChildren($this->planningWithChildren($this->labeledNavigationItems([
                'Catalog & Curricula' => CatalogCurriculaWorkbench::class,
                'Term Planning' => TermPlanningCluster::class,
                'Admission cycles' => AdmissionCycleResource::class,
            ]), $termPlanningContext)));
        }

        if ($selectedContext === User::StaffRoleAccounting) {
            return $builder->items($this->accountingNavigation($badgeCounts));
        }

        $components = match ($selectedContext) {
            User::StaffRoleFaculty => [
                'My schedule' => FacultySchedule::class,
                'My availability' => MyAvailability::class,
                'Grade rosters' => FacultyGradeRoster::class,
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

        $items = $this->labeledNavigationItems($components);

        if ($selectedContext === User::StaffRoleFaculty) {
            $items = array_map(
                fn (NavigationItem $item): NavigationItem => $item->getLabel() === 'Grade rosters'
                    ? $item->badge(fn (): ?string => ($count = $badgeCounts->facultyGradeRosterActionCount($user)) > 0 ? (string) $count : null, 'warning')
                    : $item,
                $items,
            );
        }

        if ($selectedContext === User::StaffRoleAcademicHead) {
            $items = $termPlanningContext !== null
                ? $this->planningWithChildren($items, $termPlanningContext)
                : $this->academicHeadCatalogNavigation($items);
        }

        return $builder->items($items);
    }

    /**
     * Attach the 3 Catalog & Curricula pillar child items for the Registrar sidebar
     * only while inside one of the child resource routes (matching Grades & Completion).
     *
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function catalogWithChildren(array $items): array
    {
        $children = $this->isInsideCatalogPillar()
            ? $this->catalogChildItems(includeBadges: true)
            : [];

        return array_map(function (NavigationItem $item) use ($children): NavigationItem {
            if ($item->getLabel() !== 'Catalog & Curricula') {
                return $item;
            }

            $item->isActiveWhen(fn (): bool => $this->isInsideCatalogCurricula());

            if ($children !== []) {
                $item->childItems($children);
            }

            return $item;
        }, $items);
    }

    /**
     * When Academic Head drills into Catalog & Curricula, keep Academic Oversight active
     * and expand the 3 badge-free read-only sub-items while inside any of the 3 child resource routes.
     *
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function academicHeadCatalogNavigation(array $items): array
    {
        if (! $this->isInsideCatalogCurricula()) {
            return $items;
        }

        $oversightTermId = $this->resolveOversightTermId();
        $children = $this->isInsideCatalogPillar()
            ? $this->catalogChildItems(includeBadges: false, oversightTermId: $oversightTermId)
            : [];

        return array_map(function (NavigationItem $item) use ($children, $oversightTermId): NavigationItem {
            if ($item->getLabel() !== 'Academic Oversight') {
                return $item;
            }

            if ($oversightTermId !== null) {
                $item->url(AcademicApprovals::getUrl(['termId' => $oversightTermId]));
            }

            $item->isActiveWhen(fn (): bool => true);

            if ($children !== []) {
                $item->childItems($children);
            }

            return $item;
        }, $items);
    }

    private function isInsideCatalogCurricula(): bool
    {
        $request = \Filament\Support\original_request();

        return $request->routeIs(CatalogCurriculaWorkbench::getRouteName())
            || $this->isInsideCatalogPillar();
    }

    private function isInsideCatalogPillar(): bool
    {
        $request = \Filament\Support\original_request();

        return $request->routeIs(
            'filament.admin.resources.programs.*',
            'filament.admin.resources.courses.*',
            'filament.admin.resources.curriculum-versions.*',
        );
    }

    private function resolveOversightTermId(): ?int
    {
        $component = app('livewire')->current();

        if (is_object($component)
            && property_exists($component, 'oversightTermId')
            && is_int($component->oversightTermId)
            && $component->oversightTermId > 0) {
            return $component->oversightTermId;
        }

        $request = \Filament\Support\original_request();
        $requested = $request->integer('oversightTermId') ?: $request->integer('termId');

        if ($requested > 0 && Term::query()->whereKey($requested)->exists()) {
            return $requested;
        }

        return null;
    }

    /**
     * @return list<NavigationItem>
     */
    private function catalogChildItems(bool $includeBadges, ?int $oversightTermId = null): array
    {
        $request = \Filament\Support\original_request();
        $query = $oversightTermId !== null ? ['oversightTermId' => $oversightTermId] : [];
        $badges = $includeBadges ? app(AcademicReadinessService::class)->sidebarBadges() : [];

        $programsItem = NavigationItem::make('Programs')
            ->icon(Heroicon::OutlinedAcademicCap)
            ->url(ProgramResource::getUrl('index', $query))
            ->isActiveWhen(fn (): bool => $request->routeIs('filament.admin.resources.programs.*'));

        $coursesItem = NavigationItem::make('Subjects')
            ->icon(Heroicon::OutlinedBookOpen)
            ->url(CourseResource::getUrl('index', $query))
            ->isActiveWhen(fn (): bool => $request->routeIs('filament.admin.resources.courses.*'));

        if (($badges['courses_with_drafts'] ?? 0) > 0) {
            $coursesItem->badge((string) $badges['courses_with_drafts'], 'info');
        }

        $curriculaItem = NavigationItem::make('Curricula')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->url(CurriculumVersionResource::getUrl('index', $query))
            ->isActiveWhen(fn (): bool => $request->routeIs('filament.admin.resources.curriculum-versions.*'));

        if (($badges['curricula_pending'] ?? 0) > 0) {
            $curriculaItem->badge((string) $badges['curricula_pending'], 'info');
        }

        return [
            $programsItem,
            $coursesItem,
            $curriculaItem,
        ];
    }

    /**
     * @param  list<NavigationItem>  $items
     * @param  array{termId: ?int, active: string}|null  $context
     * @return list<NavigationItem>
     */
    private function planningWithChildren(array $items, ?array $context): array
    {
        if ($context === null) {
            return $items;
        }

        return array_map(function (NavigationItem $item) use ($context): NavigationItem {
            if (! in_array($item->getLabel(), ['Term Planning', 'Academic Oversight'], true)) {
                return $item;
            }

            if ($item->getLabel() === 'Academic Oversight') {
                $item->url(AcademicApprovals::getUrl(['termId' => $context['termId']]))
                    ->isActiveWhen(fn (): bool => true);
            }

            if ($context['termId'] === null) {
                return $item->isActiveWhen(fn (): bool => true);
            }

            return $item->childItems($this->focusedTermPlanningNavigation($context));
        }, $items);
    }

    /** @return array{termId: ?int, active: string}|null */
    private function focusedTermPlanningContext(): ?array
    {
        $request = \Filament\Support\original_request();
        $routeName = $request->route()?->getName();
        $component = app('livewire')->current();
        $termIdResolvedFromComponent = false;

        $planningPages = [TermOverview::class, TermCalendar::class, TermClasses::class, TermClassProposal::class, TermResources::class, TermTimetable::class];
        $isPlanningPage = collect($planningPages)->contains(fn (string $page): bool => $routeName === $page::getRouteName());

        $termSourcePages = [ListTerms::class, CreateTerm::class, ViewTerm::class, EditTerm::class];
        $sectionSourcePages = [ListSections::class, CreateSection::class, ViewSection::class, EditSection::class];
        $isTermSource = collect($termSourcePages)->contains(fn (string $page): bool => $routeName === $page::getRouteName());
        $isSectionSource = collect($sectionSourcePages)->contains(fn (string $page): bool => $routeName === $page::getRouteName());
        $isPlanningSource = is_string($routeName) && str_starts_with($routeName, 'filament.admin.term-planning.resources.');

        if ($component instanceof TermPlanningWorkbench) {
            $isPlanningPage = true;
        } elseif ($component instanceof ViewTerm || $component instanceof EditTerm || $component instanceof ListTerms || $component instanceof CreateTerm) {
            $isTermSource = true;
        } elseif ($component instanceof ListSections || $component instanceof CreateSection || $component instanceof ViewSection || $component instanceof EditSection) {
            $isSectionSource = true;
        }

        if (! $isPlanningPage && ! $isTermSource && ! $isSectionSource && ! $isPlanningSource) {
            return null;
        }

        $termId = null;
        if ($component instanceof TermPlanningWorkbench) {
            $termId = $component->chooseTerm ? null : $component->termId;
            $termIdResolvedFromComponent = $termId !== null;
        } elseif ($component instanceof ViewTerm || $component instanceof EditTerm) {
            $termId = (int) $component->getRecord()->getKey();
            $termIdResolvedFromComponent = true;
        } elseif ($component instanceof ViewSection || $component instanceof EditSection) {
            $termId = (int) $component->getRecord()->term_id;
            $termIdResolvedFromComponent = true;
        } elseif ($component instanceof ListSections || $component instanceof CreateSection) {
            $termId = $component->termId;
            $termIdResolvedFromComponent = $termId !== null;
        } elseif (! $request->boolean('chooseTerm')) {
            $requestedTermId = $request->integer('termId');
            $termId = $requestedTermId > 0 ? $requestedTermId : null;
            if ($termId === null && ($routeName === ViewTerm::getRouteName() || $routeName === EditTerm::getRouteName())) {
                $recordId = $request->route('record');
                $termId = is_numeric($recordId) && (int) $recordId > 0 ? (int) $recordId : null;
            }
        }

        if (! $termIdResolvedFromComponent && $termId !== null && ! Term::query()->whereKey($termId)->exists()) {
            $termId = null;
        }

        $active = match (true) {
            $isSectionSource || str_contains((string) $routeName, '.resources.term-offerings.') => 'classes',
            str_contains((string) $routeName, '.resources.faculty-qualifications.'), str_contains((string) $routeName, '.resources.faculty-term-load-overrides.') => 'resources',
            str_contains((string) $routeName, '.resources.calendar-events.') => in_array($request->query('resourceView'), ['faculty', 'rooms'], true) ? 'resources' : 'overview',
            str_contains((string) $routeName, '.resources.schedule-generation-runs.'), str_contains((string) $routeName, '.resources.section-meetings.') => 'generate',
            $routeName === TermCalendar::getRouteName() => 'overview',
            $routeName === TermClasses::getRouteName() => 'classes',
            $routeName === TermResources::getRouteName() => 'resources',
            $routeName === TermTimetable::getRouteName() => 'generate',
            default => match ($request->query('viewTab')) {
                'overview' => 'overview',
                'classes' => 'classes',
                'resources' => 'resources',
                'generate', 'published' => 'generate',
                default => 'plan',
            },
        };

        return ['termId' => $termId, 'active' => $active];
    }

    /** @param array{termId: ?int, active: string} $context
     * @return list<NavigationItem>
     */
    private function focusedTermPlanningNavigation(array $context): array
    {
        $items = TermPlanningCluster::workspaceNavigation($context['termId'], $context['active']);

        foreach ($items as $item) {
            $item->icon(match ($item->getLabel()) {
                'Term overview' => Heroicon::OutlinedHome,
                'Calendar' => Heroicon::OutlinedCalendarDays,
                'Sections & classes' => Heroicon::OutlinedBookOpen,
                'Faculty & rooms' => Heroicon::OutlinedUserGroup,
                'Timetable' => Heroicon::OutlinedTableCells,
                default => null,
            });

            if ($item->getLabel() === 'Calendar') {
                $item->label('Teaching calendar');
            } elseif ($item->getLabel() === 'Sections & classes') {
                $item->label('Classes & student sections');
            }

        }

        return $items;
    }

    /** @return list<NavigationItem> */
    private function accountingNavigation(NavigationBadgeCounts $badgeCounts): array
    {
        $items = [];

        if (EnrollmentResource::canAccess()) {
            $request = \Filament\Support\original_request();
            $inside = $request->routeIs('filament.admin.resources.enrollments.*', 'filament.admin.resources.student-lifecycle-changes.*', 'filament.admin.resources.transcript-requests.*');
            $overview = $request->routeIs('filament.admin.resources.enrollments.index') && $request->query('tab', 'overview') === 'overview';
            $selected = $request->routeIs('filament.admin.resources.enrollments.index')
                ? (string) $request->query('tab', 'overview')
                : (string) $request->query('queue', 'accounts');
            $badgeKey = [
                'assessment_required' => 'assessment',
                'payment_proof' => 'proof',
                'student_changes' => 'student_changes',
            ];
            $children = collect([
                'accounts' => ['All student accounts', Heroicon::OutlinedFolderOpen],
                'assessment_required' => ['Assessment reviews', Heroicon::OutlinedDocumentCurrencyDollar],
                'payment_proof' => ['Review receipts', Heroicon::OutlinedDocumentMagnifyingGlass],
                'student_changes' => ['Student status changes', Heroicon::OutlinedClipboardDocumentCheck],
            ])->map(function (array $area, string $key) use ($inside, $selected, $badgeCounts, $badgeKey): NavigationItem {
                $child = NavigationItem::make($area[0])
                    ->icon($area[1])
                    ->url(EnrollmentResource::getUrl('index', ['tab' => $key]))
                    ->isActiveWhen(fn (): bool => $inside && $selected === $key);

                if (isset($badgeKey[$key])) {
                    $metric = $badgeKey[$key];
                    $child->badge(
                        fn (): ?string => ($count = $badgeCounts->accounting()[$metric]) > 0 ? (string) $count : null,
                        'warning',
                    );
                }

                return $child;
            })->values()->all();

            $items[] = NavigationItem::make('Overview')->icon(Heroicon::OutlinedHome)
                ->url(EnrollmentResource::getUrl('index'))
                ->isActiveWhen(fn (): bool => $overview);
            $items[] = NavigationItem::make('Student accounts')->icon(Heroicon::OutlinedBanknotes)
                ->url(EnrollmentResource::getUrl('index', ['tab' => 'accounts']))
                ->isActiveWhen(fn (): bool => $inside && ! $overview)
                ->badge(
                    fn (): ?string => ($count = $badgeCounts->accounting()['unique_total']) > 0 ? (string) $count : null,
                    'warning',
                )
                ->badgeTooltip(fn (): ?string => $badgeCounts->accountingTooltip())
                ->childItems($children);
        }

        return [
            ...$items,
            ...$this->labeledNavigationItems([
                'Fee plans' => FeePlanResource::class,
                'Payment channels' => PaymentChannels::class,
            ]),
        ];
    }

    private function labeledNavigationItems(array $components): array
    {
        return collect($components)->flatMap(function (string $component, string $label): array {
            if (! $component::canAccess()) {
                return [];
            }

            return collect($component::getNavigationItems())
                ->map(function (NavigationItem $item) use ($component, $label): NavigationItem {
                    $request = \Filament\Support\original_request();
                    $termId = $request->attributes->get('tala.selected_term_id', $request->integer('termId'));
                    if ($termId > 0 && in_array($component, [FacultySchedule::class, MyAvailability::class], true)) {
                        $item->url($component::getUrl(['termId' => $termId]));
                    }

                    return $item->label($label);
                })->all();
        })->values()->all();
    }

    /**
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function gradesWithChildren(array $items, NavigationBadgeCounts $badgeCounts): array
    {
        if (! GradesAndCompletion::canAccess()) {
            return $items;
        }

        $items = array_map(
            fn (NavigationItem $item): NavigationItem => $item
                ->badge(fn (): ?string => ($count = $badgeCounts->registrarGrades()['total']) > 0 ? (string) $count : null, 'warning')
                ->badgeTooltip(fn (): ?string => $badgeCounts->registrarGradesTooltip()),
            $items,
        );

        $request = \Filament\Support\original_request();
        $inside = $request->routeIs(GradesAndCompletion::getRouteName(), CompletionAndTor::getRouteName())
            || $request->routeIs('filament.admin.resources.grade-rosters.*', 'filament.admin.resources.student-lifecycle-changes.*', 'filament.admin.resources.transcript-requests.*')
            || ($request->query('viewTab') === 'academic-progress' && $request->routeIs('filament.admin.resources.student-profiles.*'));

        if (! $inside) {
            return $items;
        }

        if ($request->routeIs(GradesAndCompletion::getRouteName())
            && ! array_key_exists((string) $request->query('viewTab', 'overview'), GradesAndCompletion::tabs())) {
            return $items;
        }

        $selected = match (true) {
            $request->routeIs('filament.admin.resources.student-profiles.*') => 'academic-progress',
            $request->routeIs('filament.admin.resources.student-lifecycle-changes.*') => 'lifecycle',
            $request->routeIs(CompletionAndTor::getRouteName(), 'filament.admin.resources.transcript-requests.*') => 'completion-tor',
            default => (string) $request->query('viewTab', 'grade-review'),
        };
        $selected = array_key_exists($selected, GradesAndCompletion::tabs()) ? $selected : 'grade-review';
        $areas = GradesAndCompletion::workAreas();
        $badgeKeys = [
            'grade-review' => 'grade_review',
            'inc-corrections' => 'inc_corrections',
            'completion-tor' => 'completion_tor',
        ];
        $children = collect(GradesAndCompletion::tabs())->map(function (string $label, string $key) use ($areas, $selected, $badgeCounts, $badgeKeys): NavigationItem {
            $child = NavigationItem::make($label)
                ->icon($areas[$key]['icon'])
                ->url(GradesAndCompletion::getUrl(['viewTab' => $key]))
                ->isActiveWhen(fn (): bool => $selected === $key);

            if (isset($badgeKeys[$key])) {
                $metric = $badgeKeys[$key];
                $child->badge(
                    fn (): ?string => ($count = $badgeCounts->registrarGrades()[$metric]) > 0 ? (string) $count : null,
                    'warning',
                );
            }

            return $child;
        })->values()->all();

        return array_map(fn (NavigationItem $item): NavigationItem => $item->childItems($children), $items);
    }

    /**
     * Admissions shows the Registrar-action count and, while inside Admissions, one sub-item per queue (#61 F101).
     *
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function admissionsWithQueues(array $items, NavigationBadgeCounts $badgeCounts): array
    {
        if ($items === [] || ! AdmissionApplicationResource::canAccess()) {
            return $items;
        }

        $onList = fn (): bool => request()->routeIs(ListAdmissionApplications::getRouteName());

        $children = collect(AdmissionQueueCounts::labels())->map(function (string $label, string $queue) use ($badgeCounts, $onList): NavigationItem {
            $child = NavigationItem::make($label)
                ->icon(match ($queue) {
                    AdmissionQueueCounts::NeedsReview => Heroicon::OutlinedDocumentMagnifyingGlass,
                    AdmissionQueueCounts::WaitingForApplicant => Heroicon::OutlinedClock,
                    AdmissionQueueCounts::RegistrarClearance => Heroicon::OutlinedClipboardDocumentCheck,
                    AdmissionQueueCounts::ReadyForEnrollment => Heroicon::OutlinedCheckCircle,
                    AdmissionQueueCounts::Expired => Heroicon::OutlinedExclamationTriangle,
                    AdmissionQueueCounts::Closed => Heroicon::OutlinedArchiveBox,
                    default => Heroicon::OutlinedQueueList,
                })
                ->url(ListAdmissionApplications::getUrl(['tab' => $queue]))
                ->isActiveWhen(fn (): bool => $onList() && (request()->query('tab') ?? AdmissionQueueCounts::NeedsReview) === $queue);

            if ($queue !== AdmissionQueueCounts::Closed) {
                $color = in_array($queue, AdmissionQueueCounts::registrarActionQueues(), true) ? 'warning' : 'gray';
                $child->badge(
                    fn (): ?string => ($count = $badgeCounts->admissions()[$queue]) > 0 ? (string) $count : null,
                    $color,
                );
            }

            return $child;
        })->values()->all();

        return array_map(fn (NavigationItem $item): NavigationItem => $item
            ->badge(fn (): ?string => ($count = $badgeCounts->admissions()['action']) > 0 ? (string) $count : null, 'warning')
            ->childItems($children), $items);
    }
}
