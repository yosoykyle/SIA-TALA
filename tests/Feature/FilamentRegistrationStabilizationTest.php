<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentRegistrationStabilizationTest extends TestCase
{
    /**
     * @return array<string, array{route: string}>
     */
    public static function deferredStaleAdminRoutes(): array
    {
        return [
            'admission readiness dashboard page' => ['route' => 'filament.admin.pages.admission-readiness-dashboard'],
            'admission capacity plans' => ['route' => 'filament.admin.resources.admission-capacity-plans.index'],
            'admission offerings' => ['route' => 'filament.admin.resources.admission-offerings.index'],
            'cor verifications' => ['route' => 'filament.admin.resources.cor-verifications.index'],
            'curricula' => ['route' => 'filament.admin.resources.curricula.index'],
            'delivery patterns' => ['route' => 'filament.admin.resources.delivery-patterns.index'],
            'document requirement items' => ['route' => 'filament.admin.resources.document-requirement-items.index'],
            'document uploads' => ['route' => 'filament.admin.resources.document-uploads.index'],
            'enrollment subjects' => ['route' => 'filament.admin.resources.enrollment-subjects.index'],
            'faculty subject eligibilities' => ['route' => 'filament.admin.resources.faculty-subject-eligibilities.index'],
            'fee templates' => ['route' => 'filament.admin.resources.fee-templates.index'],
            'grade corrections' => ['route' => 'filament.admin.resources.grade-corrections.index'],
            'grades' => ['route' => 'filament.admin.resources.grades.index'],
            'grade submission packages' => ['route' => 'filament.admin.resources.grade-submission-packages.index'],
            'installment policies' => ['route' => 'filament.admin.resources.installment-policies.index'],
            'installment policy milestones' => ['route' => 'filament.admin.resources.installment-policy-milestones.index'],
            'promissory notes' => ['route' => 'filament.admin.resources.promissory-notes.index'],
            'subjects' => ['route' => 'filament.admin.resources.subjects.index'],
            'system settings' => ['route' => 'filament.admin.resources.system-settings.index'],
            'generic settings page' => ['route' => 'filament.admin.pages.settings'],
            'generic cms settings' => ['route' => 'filament.admin.pages.cms-settings'],
            'generic page builder' => ['route' => 'filament.admin.pages.page-builder'],
            'cms pages' => ['route' => 'filament.admin.resources.cms-pages.index'],
        ];
    }

    #[DataProvider('deferredStaleAdminRoutes')]
    public function test_stale_admin_surfaces_are_deferred_from_route_registration(string $route): void
    {
        $this->assertFalse(
            Route::has($route),
            "Unexpected stale Filament route is registered: {$route}",
        );
    }

    /**
     * @return array<string, array{route: string}>
     */
    public static function retainedPanelAndFoundationRoutes(): array
    {
        return [
            'admin login' => ['route' => 'filament.admin.auth.login'],
            'applicant login' => ['route' => 'filament.applicant.auth.login'],
            'applicant registration' => ['route' => 'filament.applicant.auth.register'],
            'student login' => ['route' => 'filament.student.auth.login'],
            'admin users' => ['route' => 'filament.admin.resources.users.index'],
            'system health' => ['route' => 'filament.admin.pages.system-health'],
            'governance and audit' => ['route' => 'filament.admin.pages.governance-audit'],
            'faq entries' => ['route' => 'filament.admin.public-content.resources.faq-entries.index'],
            'public notices' => ['route' => 'filament.admin.public-content.resources.public-notices.index'],
            'legacy faq recovery' => ['route' => 'filament.admin.legacy-faq.index'],
            'scheduling blocks' => ['route' => 'filament.admin.resources.calendar-events.index'],
            'schedule generation runs' => ['route' => 'filament.admin.resources.schedule-generation-runs.index'],
        ];
    }

    #[DataProvider('retainedPanelAndFoundationRoutes')]
    public function test_accepted_panel_and_foundation_routes_remain_registered(string $route): void
    {
        $this->assertTrue(
            Route::has($route),
            "Expected Filament route is not registered: {$route}",
        );
    }

    public function test_roles_and_permissions_route_is_retired(): void
    {
        $this->assertFalse(Route::has('filament.admin.resources.roles.index'));
    }

    public function test_native_stack_manifests_exclude_react_and_duplicate_workflow_engines(): void
    {
        $packageJson = json_decode(file_get_contents(base_path('package.json')), true, 512, JSON_THROW_ON_ERROR);
        $dependencies = array_merge(
            array_keys($packageJson['dependencies'] ?? []),
            array_keys($packageJson['devDependencies'] ?? []),
        );

        $this->assertNotContains('react', $dependencies, 'React must not be present in package.json dependencies.');
        $this->assertNotContains('react-dom', $dependencies, 'react-dom must not be present in package.json dependencies.');
        $this->assertNotContains('vue', $dependencies, 'Vue must not be present in package.json dependencies.');
        $this->assertNotContains('redux', $dependencies, 'Redux must not be present in package.json dependencies.');
        $this->assertNotContains('pinia', $dependencies, 'Pinia must not be present in package.json dependencies.');
        $this->assertNotContains('zustand', $dependencies, 'Zustand must not be present in package.json dependencies.');
        $this->assertContains('alpinejs', $dependencies, 'Native stack requires Alpine.js.');
        $this->assertContains('tailwindcss', $dependencies, 'Native stack requires Tailwind CSS.');

        $composerJson = json_decode(file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
        $composerRequires = array_merge(
            array_keys($composerJson['require'] ?? []),
            array_keys($composerJson['require-dev'] ?? []),
        );

        $this->assertContains('filament/filament', $composerRequires, 'Native stack requires Filament.');
        $this->assertContains('livewire/livewire', $composerRequires, 'Native stack requires Livewire.');
        $this->assertNotContains('spatie/laravel-workflow', $composerRequires, 'Duplicate workflow engines must be absent.');
        $this->assertNotContains('brexis/laravel-workflow', $composerRequires, 'Duplicate workflow engines must be absent.');
        $this->assertNotContains('spatie/laravel-settings', $composerRequires, 'Generic settings packages must be absent.');
        $this->assertNotContains('statamic/cms', $composerRequires, 'Generic CMS packages must be absent.');
        $this->assertNotContains('orchid/platform', $composerRequires, 'Generic admin/CMS platforms must be absent.');
        $this->assertNotContains('backpack/crud', $composerRequires, 'Generic CMS packages must be absent.');
        $this->assertNotContains('laravel/nova', $composerRequires, 'Generic admin packages must be absent.');

        // Absence of generic settings and CMS surfaces across registered routes
        $this->assertFalse(Route::has('filament.admin.resources.system-settings.index'), 'Generic system settings resource must not be registered.');
        $this->assertFalse(Route::has('filament.admin.pages.settings'), 'Generic settings page must not be registered.');
        $this->assertFalse(Route::has('filament.admin.pages.cms-settings'), 'Generic CMS settings page must not be registered.');
        $this->assertFalse(Route::has('filament.admin.pages.page-builder'), 'Generic page builder surface must not be registered.');
        $this->assertFalse(Route::has('filament.admin.resources.cms-pages.index'), 'Generic CMS pages resource must not be registered.');
        $this->assertFalse(Route::has('filament.student.pages.settings'), 'Generic settings surface must not exist in student panel.');
        $this->assertFalse(Route::has('filament.applicant.pages.settings'), 'Generic settings surface must not exist in applicant panel.');
        $this->assertFalse(Route::has('filament.admin.pages.cms'), 'Generic CMS surface must not exist.');

        // Native Blade views and Filament provider structures are present
        $this->assertDirectoryExists(resource_path('views'), 'Native Blade views must exist.');
        $this->assertDirectoryExists(app_path('Providers/Filament'), 'Native Filament panel providers must exist.');

        $appJs = file_get_contents(resource_path('js/app.js'));
        $this->assertStringNotContainsStringIgnoringCase('react', $appJs);
        $this->assertStringNotContainsStringIgnoringCase('redux', $appJs);
        $this->assertStringNotContainsStringIgnoringCase('vue', $appJs);
    }
}
