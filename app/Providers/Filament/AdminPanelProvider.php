<?php

namespace App\Providers\Filament;

use App\Actions\Authentication\TalaAppAuthentication;
use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Clusters\Admissions;
use App\Filament\Clusters\PublicContent;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\AcademicReadiness;
use App\Filament\Pages\AssistedAdmissionApplication;
use App\Filament\Pages\Auth\AccountSecurity;
use App\Filament\Pages\Auth\ContextualLogin;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Pages\ClassPlanning;
use App\Filament\Pages\CompletionAndTor;
use App\Filament\Pages\FacultyGradeRoster;
use App\Filament\Pages\FacultySchedule;
use App\Filament\Pages\GovernanceAudit;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Pages\MyAvailability;
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
use App\Filament\Widgets\StaffRoleWorkspaceOverviewWidget;
use App\Http\Middleware\EnforceCanonicalSessionPolicy;
use App\Http\Middleware\EnsureStaffMfaIsEnabled;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\FaqEntry;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\AdmissionQueueCounts;
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
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
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
            ->brandName('Servitech Institute Asia — Staff Workspace')
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
                    ->passwordReset(fn (AuthPageConfig $config) => $config
                        ->usingPage(RequestPasswordReset::class)
                        ->usingResetPage(ResetPassword::class)
                    )
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
                AcademicReadiness::class,
                ClassPlanning::class,
                FacultyGradeRoster::class,
                MyAvailability::class,
                FacultySchedule::class,
                GradesAndCompletion::class,
                CompletionAndTor::class,
                SystemHealth::class,
                GovernanceAudit::class,
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

        $termPlanningContext = $this->focusedTermPlanningContext();
        if ($selectedContext === User::StaffRoleRegistrar) {
            return $builder->group('Learner work', [
                ...$this->admissionsWithQueues($this->labeledNavigationItems(['Admissions' => Admissions::class])),
                ...$this->labeledNavigationItems([
                    'Students & Enrollment' => EnrollmentResource::class,
                    'Grades & Completion' => GradesAndCompletion::class,
                ]),
            ])->group('Academic setup', $this->planningWithChildren($this->labeledNavigationItems([
                'Catalog & Curricula' => CatalogCurriculaWorkbench::class,
                'Term Planning' => TermPlanningCluster::class,
            ]), $termPlanningContext));
        }

        $components = match ($selectedContext) {
            User::StaffRoleAccounting => [
                'Student Accounts' => EnrollmentResource::class,
                'Fee Plans' => FeePlanResource::class,
            ],
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

        return $builder->items($selectedContext === User::StaffRoleAcademicHead ? $this->planningWithChildren($items, $termPlanningContext) : $items);
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
     * Admissions shows the Registrar-action count and, while inside Admissions, one sub-item per queue (#61 F101).
     *
     * @param  list<NavigationItem>  $items
     * @return list<NavigationItem>
     */
    private function admissionsWithQueues(array $items): array
    {
        if ($items === [] || ! AdmissionApplicationResource::canAccess()) {
            return $items;
        }

        $queues = app(AdmissionQueueCounts::class);
        $counts = collect(AdmissionQueueCounts::labels())->map(fn (string $label, string $queue): int => $queues->count($queue));
        $actionCount = collect(AdmissionQueueCounts::registrarActionQueues())->sum(fn (string $queue): int => $counts[$queue]);
        $onList = fn (): bool => request()->routeIs(ListAdmissionApplications::getRouteName());

        $children = $counts->map(fn (int $count, string $queue): NavigationItem => NavigationItem::make(AdmissionQueueCounts::labels()[$queue])
            ->icon(match ($queue) {
                AdmissionQueueCounts::NeedsReview => Heroicon::OutlinedDocumentMagnifyingGlass,
                AdmissionQueueCounts::WaitingForApplicant => Heroicon::OutlinedClock,
                AdmissionQueueCounts::RegistrarClearance => Heroicon::OutlinedClipboardDocumentCheck,
                AdmissionQueueCounts::ReadyForEnrollment => Heroicon::OutlinedCheckCircle,
                AdmissionQueueCounts::Closed => Heroicon::OutlinedArchiveBox,
                default => Heroicon::OutlinedQueueList,
            })
            ->url(ListAdmissionApplications::getUrl(['tab' => $queue]))
            ->badge($count ?: null, in_array($queue, AdmissionQueueCounts::registrarActionQueues(), true) ? 'warning' : 'gray')
            ->isActiveWhen(fn (): bool => $onList() && (request()->query('tab') ?? AdmissionQueueCounts::NeedsReview) === $queue))
            ->values()->all();

        return array_map(fn (NavigationItem $item): NavigationItem => $item
            ->badge($actionCount ?: null, 'warning')
            ->childItems($children), $items);
    }
}
