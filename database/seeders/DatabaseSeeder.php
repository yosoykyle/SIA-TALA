<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the fixed authorization vocabulary required by the MVP baseline:
     * the 7 canonical roles, the canonical action-level permissions, and the
     * PRD §2.3 role→permission assignments. Idempotent and safe to re-run.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->canonicalRoles() as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach ($this->canonicalPermissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach ($this->rolePermissionMap() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private function canonicalRoles(): array
    {
        return [
            'applicant',
            'student',
            'registrar',
            'accounting',
            'faculty',
            'academic-head',
            'system-super-admin',
        ];
    }

    /**
     * The canonical action-level permissions the application authorizes against
     * (PRD §2.3). This is the exact set referenced by policies and services.
     *
     * @return list<string>
     */
    private function canonicalPermissions(): array
    {
        return [
            'approve-documents',
            'approve-promissory-notes',
            'authorize-overrides',
            'create-assessments',
            'encode-grades',
            'evaluate-transferees',
            'finalize-grades',
            'manage-admission-setup',
            'manage-cor-verifications',
            'manage-curricula',
            'manage-faculty-subject-eligibilities',
            'manage-faqs',
            'manage-grade-corrections',
            'manage-schedules',
            'manage-sections',
            'manage-student-profiles',
            'post-accounting-adjustments',
            'process-payments',
            'request-grade-corrections',
            'review-lock-faculty-availability',
            'submit-faculty-availability',
            'verify-grade-submissions',
            'view-class-list',
            'view-faculty-availability',
            'view-global-records',
            'view-grade-submission-progress',
        ];
    }

    /**
     * Canonical role→permission assignments per PRD §2.3.1–2.3.7, corroborated by
     * the role+permission pairings enforced in the policies and domain services.
     * Assignments are synced declaratively so this seeder is the single source of truth.
     *
     * @return array<string, list<string>>
     */
    private function rolePermissionMap(): array
    {
        return [
            // §2.3.1 Applicant actions are role- and ownership-scoped; no action permissions.
            'applicant' => [],

            // §2.3.2 Student may request posted-grade corrections (recorded by Registrar).
            'student' => [
                'request-grade-corrections',
            ],

            // §2.3.4 Registrar: admissions, records, COR, sections/scheduling, grade review,
            //         curriculum/eligibility setup, and faculty-availability review.
            'registrar' => [
                'approve-documents',
                'evaluate-transferees',
                'manage-student-profiles',
                'manage-admission-setup',
                'manage-cor-verifications',
                'verify-grade-submissions',
                'view-grade-submission-progress',
                'manage-grade-corrections',
                'manage-schedules',
                'manage-sections',
                'manage-curricula',
                'manage-faculty-subject-eligibilities',
                'review-lock-faculty-availability',
                'view-faculty-availability',
            ],

            // §2.3.5 Accounting: fee setup, assessment, payments, adjustments, accommodations.
            'accounting' => [
                'create-assessments',
                'process-payments',
                'post-accounting-adjustments',
                'approve-promissory-notes',
            ],

            // §2.3.3 Faculty: own availability, assigned class lists, grade encoding/submission.
            'faculty' => [
                'encode-grades',
                'finalize-grades',
                'submit-faculty-availability',
                'view-class-list',
            ],

            // §2.3.6 Academic Head: academic overrides, curriculum/eligibility governance,
            //         faculty-availability review, and read-only global records oversight.
            'academic-head' => [
                'authorize-overrides',
                'manage-curricula',
                'manage-faculty-subject-eligibilities',
                'review-lock-faculty-availability',
                'view-faculty-availability',
                'view-global-records',
            ],

            // §2.3.7 System Super Admin is scoped to configuration/audit; its operational
            //         surfaces are role-gated, so the only action permission is FAQ content.
            'system-super-admin' => [
                'manage-faqs',
            ],
        ];
    }
}
