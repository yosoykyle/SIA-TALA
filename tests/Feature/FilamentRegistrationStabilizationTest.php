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
            'accounting adjustments' => ['route' => 'filament.admin.resources.accounting-adjustments.index'],
            'admission capacity plans' => ['route' => 'filament.admin.resources.admission-capacity-plans.index'],
            'admission offerings' => ['route' => 'filament.admin.resources.admission-offerings.index'],
            'cor verifications' => ['route' => 'filament.admin.resources.cor-verifications.index'],
            'curricula' => ['route' => 'filament.admin.resources.curricula.index'],
            'delivery patterns' => ['route' => 'filament.admin.resources.delivery-patterns.index'],
            'document requirement items' => ['route' => 'filament.admin.resources.document-requirement-items.index'],
            'document uploads' => ['route' => 'filament.admin.resources.document-uploads.index'],
            'enrollment subjects' => ['route' => 'filament.admin.resources.enrollment-subjects.index'],
            'faculty availability change requests' => ['route' => 'filament.admin.resources.faculty-availability-change-requests.index'],
            'faculty availability periods' => ['route' => 'filament.admin.resources.faculty-availability-periods.index'],
            'faculty availability submissions' => ['route' => 'filament.admin.resources.faculty-availability-submissions.index'],
            'faculty subject eligibilities' => ['route' => 'filament.admin.resources.faculty-subject-eligibilities.index'],
            'faq entries' => ['route' => 'filament.admin.resources.faq-entries.index'],
            'fee templates' => ['route' => 'filament.admin.resources.fee-templates.index'],
            'grade corrections' => ['route' => 'filament.admin.resources.grade-corrections.index'],
            'grades' => ['route' => 'filament.admin.resources.grades.index'],
            'grade submission packages' => ['route' => 'filament.admin.resources.grade-submission-packages.index'],
            'installment policies' => ['route' => 'filament.admin.resources.installment-policies.index'],
            'installment policy milestones' => ['route' => 'filament.admin.resources.installment-policy-milestones.index'],
            'promissory notes' => ['route' => 'filament.admin.resources.promissory-notes.index'],
            'schedule generation runs' => ['route' => 'filament.admin.resources.schedule-generation-runs.index'],
            'subjects' => ['route' => 'filament.admin.resources.subjects.index'],
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
            'admin roles' => ['route' => 'filament.admin.resources.roles.index'],
            'admin activities' => ['route' => 'filament.admin.resources.activities.index'],
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
}
