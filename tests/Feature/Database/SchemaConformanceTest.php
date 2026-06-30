<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SchemaConformanceTest extends TestCase
{
    private const PLATFORM_TABLES = [
        'activity_log', 'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
        'migrations', 'model_has_permissions', 'model_has_roles', 'passkeys',
        'password_reset_tokens', 'permissions', 'role_has_permissions', 'roles',
        'sessions', 'users', 'webhook_calls',
    ];

    private const APPLICATION_TABLES = [
        'academic_years', 'admission_requirement_policies', 'applicant_intakes',
        'assessment_lines', 'assessments', 'calendar_events', 'candidate_schedule_rows',
        'checklist_items', 'course_components', 'course_enrollments', 'course_requirements',
        'course_specifications', 'courses', 'curriculum_entries', 'curriculum_versions',
        'document_evidence', 'duplicate_profile_resolutions', 'enrollment_exceptions',
        'enrollment_gate_results', 'enrollment_seat_reservations', 'enrollments',
        'faculty_qualifications', 'faculty_term_load_overrides', 'fee_rules',
        'financial_accommodations', 'grade_outcome_events', 'grade_roster_rows',
        'grade_rosters', 'graduation_review_batches', 'graduation_review_members',
        'graduation_snapshots', 'holds', 'import_batches', 'late_grade_authorizations',
        'ledger_entries', 'operational_events', 'output_access_logs', 'payment_allocations',
        'payment_attempts', 'payment_schedule_rows', 'payments', 'program_shift_credit_entries',
        'programs', 'room_features', 'rooms', 'schedule_revision_events', 'schedule_runs',
        'scheduling_demands', 'section_delivery_groups', 'section_meetings', 'sections',
        'student_lifecycle_changes', 'student_profiles', 'student_schedule_bindings',
        'system_settings', 'term_offerings', 'terms',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertNotSame('tala_db', DB::connection()->getDatabaseName());
        $this->assertSame(DB::connection()->getDatabaseName(), DB::selectOne('SELECT DATABASE() AS name')->name);
    }

    public function test_exact_clean_mvp_table_inventory_is_present(): void
    {
        $actual = collect(DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"))
            ->pluck('TABLE_NAME')
            ->all();
        $expected = [...self::APPLICATION_TABLES, ...self::PLATFORM_TABLES];
        sort($expected);

        $this->assertCount(57, self::APPLICATION_TABLES);
        $this->assertCount(17, self::PLATFORM_TABLES);
        $this->assertCount(74, $actual);
        $this->assertSame($expected, $actual);
    }

    public function test_critical_source_ownership_columns_are_conformant(): void
    {
        $this->assertColumns('scheduling_demands', ['term_offering_id', 'course_component_id', 'section_delivery_group_id', 'demand_key']);
        $this->assertColumns('candidate_schedule_rows', ['schedule_run_id', 'scheduling_demand_id', 'meeting_sequence']);
        $this->assertColumns('section_meetings', ['schedule_run_id', 'scheduling_demand_id', 'meeting_sequence', 'room_id']);
        $this->assertColumns('enrollment_exceptions', ['enrollment_id', 'student_profile_id', 'term_id', 'exception_type', 'enrollment_gate_result_id', 'target_term_offering_id']);
        $this->assertColumns('payment_attempts', ['assessment_id', 'student_profile_id', 'internal_reference']);
        $this->assertColumns('payments', ['payment_attempt_id', 'evidence_status', 'or_number', 'provider_reference']);
        $this->assertColumns('payment_allocations', ['payment_id', 'assessment_line_id', 'payment_schedule_row_id', 'prior_balance_ledger_entry_id']);
        $this->assertColumns('ledger_entries', ['source_type', 'source_id', 'reverses_entry_id', 'adjusts_entry_id']);
        $this->assertColumns('grade_outcome_events', ['grade_roster_row_id', 'previous_value', 'new_value', 'previous_category', 'new_category']);
        $this->assertColumns('holds', ['hold_type', 'reason', 'staff_only_reason', 'student_message', 'resolution_requirement']);

        $this->assertFalse(Schema::hasColumn('student_profiles', 'current_balance'));
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'running_balance'));
        $this->assertFalse(Schema::hasColumn('sections', 'enrolled_count'));
        $this->assertFalse(Schema::hasColumn('enrollment_seat_reservations', 'payment_id'));
        $this->assertFalse(Schema::hasColumn('payments', 'section_id'));
        $this->assertFalse(Schema::hasColumn('holds', 'type'));
        $this->assertFalse(Schema::hasColumn('holds', 'staff_reason'));
        $this->assertFalse(Schema::hasColumn('holds', 'student_reason'));
    }

    public function test_critical_foreign_keys_and_history_preserving_delete_rules_exist(): void
    {
        $this->assertForeignKey('candidate_schedule_rows', 'scheduling_demand_id', 'scheduling_demands', 'RESTRICT');
        $this->assertForeignKey('section_meetings', 'scheduling_demand_id', 'scheduling_demands', 'RESTRICT');
        $this->assertForeignKey('student_schedule_bindings', 'section_meeting_id', 'section_meetings', 'RESTRICT');
        $this->assertForeignKey('enrollment_seat_reservations', 'section_id', 'sections', 'RESTRICT');
        $this->assertForeignKey('enrollment_exceptions', 'enrollment_gate_result_id', 'enrollment_gate_results', 'RESTRICT');
        $this->assertForeignKey('payments', 'payment_attempt_id', 'payment_attempts', 'RESTRICT');
        $this->assertForeignKey('payment_allocations', 'payment_id', 'payments', 'RESTRICT');
        $this->assertForeignKey('ledger_entries', 'payment_allocation_id', 'payment_allocations', 'RESTRICT');
        $this->assertForeignKey('grade_roster_rows', 'course_enrollment_id', 'course_enrollments', 'RESTRICT');
        $this->assertForeignKey('grade_outcome_events', 'grade_roster_row_id', 'grade_roster_rows', 'RESTRICT');
        $this->assertForeignKey('holds', 'student_profile_id', 'student_profiles', 'RESTRICT');
        $this->assertForeignKey('output_access_logs', 'student_profile_id', 'student_profiles', 'RESTRICT');

        $cascadingOfficialForeignKeys = DB::select("SELECT kcu.table_name, kcu.column_name FROM information_schema.key_column_usage kcu JOIN information_schema.referential_constraints rc ON rc.constraint_schema = kcu.constraint_schema AND rc.constraint_name = kcu.constraint_name WHERE kcu.constraint_schema = DATABASE() AND rc.delete_rule = 'CASCADE' AND kcu.table_name NOT IN ('model_has_permissions', 'model_has_roles', 'role_has_permissions', 'passkeys', 'room_features')");
        $this->assertSame([], $cascadingOfficialForeignKeys);
    }

    public function test_uniqueness_indexes_checks_and_money_precision_match_contract(): void
    {
        $this->assertUniqueIndex('enrollments', ['student_profile_id', 'term_id']);
        $this->assertUniqueIndex('course_enrollments', ['enrollment_id', 'term_offering_id']);
        $this->assertUniqueIndex('scheduling_demands', ['term_offering_id', 'course_component_id', 'section_delivery_group_id']);
        $this->assertUniqueIndex('grade_roster_rows', ['course_enrollment_id']);
        $this->assertUniqueIndex('payments', ['or_number']);
        $this->assertUniqueIndex('payments', ['provider_reference']);

        foreach ([
            ['fee_rules', 'rate', 12, 2],
            ['assessments', 'total', 12, 2],
            ['assessment_lines', 'amount', 12, 2],
            ['payment_attempts', 'amount', 12, 2],
            ['payments', 'amount', 12, 2],
            ['payment_allocations', 'amount', 12, 2],
            ['ledger_entries', 'amount', 12, 2],
            ['course_specifications', 'credit_units', 5, 2],
            ['course_components', 'weekly_contact_hours', 5, 2],
            ['grade_roster_rows', 'computed_average', 7, 4],
        ] as [$table, $column, $precision, $scale]) {
            $definition = DB::selectOne('SELECT numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$table, $column]);
            $this->assertNotNull($definition, "$table.$column is missing");
            $this->assertSame($precision, (int) $definition->NUMERIC_PRECISION);
            $this->assertSame($scale, (int) $definition->NUMERIC_SCALE);
        }

        $checks = collect(DB::select("SELECT constraint_name FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK'"))->pluck('CONSTRAINT_NAME');
        foreach (['checklist_items_owner_check', 'payment_schedule_owner_check', 'payment_allocations_target_check', 'grade_rows_range_check'] as $check) {
            $this->assertTrue($checks->contains($check), "Missing check constraint $check");
        }
    }

    public function test_foundation_seeder_creates_only_the_seven_canonical_roles(): void
    {
        $this->seed();

        $this->assertSame([
            'academic-head', 'accounting', 'applicant', 'faculty', 'registrar', 'student', 'system-super-admin',
        ], DB::table('roles')->orderBy('name')->pluck('name')->all());
        $this->assertSame(0, DB::table('permissions')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    /** @param list<string> $columns */
    private function assertColumns(string $table, array $columns): void
    {
        foreach ($columns as $column) {
            $this->assertTrue(Schema::hasColumn($table, $column), "$table.$column is missing");
        }
    }

    private function assertForeignKey(string $table, string $column, string $referencedTable, string $deleteRule): void
    {
        $foreignKey = DB::selectOne('SELECT kcu.referenced_table_name, rc.delete_rule FROM information_schema.key_column_usage kcu JOIN information_schema.referential_constraints rc ON rc.constraint_schema = kcu.constraint_schema AND rc.constraint_name = kcu.constraint_name WHERE kcu.constraint_schema = DATABASE() AND kcu.table_name = ? AND kcu.column_name = ?', [$table, $column]);
        $this->assertNotNull($foreignKey, "Missing foreign key $table.$column");
        $this->assertSame($referencedTable, $foreignKey->REFERENCED_TABLE_NAME);
        $this->assertSame($deleteRule, $foreignKey->DELETE_RULE);
    }

    /** @param list<string> $columns */
    private function assertUniqueIndex(string $table, array $columns): void
    {
        $indexes = collect(DB::select('SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns_list FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 GROUP BY index_name', [$table]))
            ->pluck('columns_list')
            ->all();
        $this->assertContains(implode(',', $columns), $indexes, 'Missing unique index on '.$table.' ('.implode(', ', $columns).')');
    }
}
