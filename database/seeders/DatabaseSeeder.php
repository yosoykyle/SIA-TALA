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

        // Prune any permission outside the canonical set so retired slugs do not
        // persist across re-seeds of an existing database (idempotent).
        Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', $this->canonicalPermissions())
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->call(AdmissionRequirementPolicySeeder::class);
        $this->call(FaqEntrySeeder::class);
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
            'authorize-overrides',
            'create-assessments',
            'evaluate-transferees',
            'manage-admission-setup',
            'manage-curricula',
            'manage-faqs',
            'manage-schedules',
            'manage-sections',
            'manage-student-profiles',
            'post-accounting-adjustments',
            'process-payments',
            'view-global-records',
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

            // §2.3.2 Posted-grade corrections are recorded by the Registrar after the physical
            //         school policy (§10.4); the student has no in-TALA action permission.
            'student' => [],

            // §2.3.4 Registrar: admissions, records, COR, sections/scheduling, and curriculum
            //         setup; grade review (Post & Release, INC/correction recording) runs through
            //         the role-gated Grade Roster workflow rather than dedicated permission slugs.
            'registrar' => [
                'approve-documents',
                'evaluate-transferees',
                'manage-student-profiles',
                'manage-admission-setup',
                'manage-schedules',
                'manage-sections',
                'manage-curricula',
            ],

            // §2.3.5 Accounting: fee setup, assessment, payments, adjustments, accommodations.
            'accounting' => [
                'create-assessments',
                'process-payments',
                'post-accounting-adjustments',
            ],

            // §2.3.3 Faculty class lists + grade encoding/submission run through role-gated
            //         surfaces (the FacultyGradeRoster page), not dedicated permission slugs; no
            //         action permissions.
            'faculty' => [],

            // §2.3.6 Academic Head: academic overrides, curriculum/eligibility governance,
            //         faculty-availability review, and read-only global records oversight.
            'academic-head' => [
                'authorize-overrides',
                'manage-curricula',
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
