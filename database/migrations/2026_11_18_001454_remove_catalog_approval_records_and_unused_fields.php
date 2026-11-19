<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire Catalog approval records and fields that serve no Servitech workflow.
 *
 * Program approvals, curriculum approval references, the separate "recorded approved"
 * curriculum step, subject approval/effective-date metadata, unused prerequisite
 * metadata and external-qualification source evidence are removed. Recorded-approved
 * curricula return to Draft so they can be activated through the single activation step.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('curriculum_versions')->where('state', 'RECORDED_APPROVED')->update(['state' => 'DRAFT']);

        Schema::dropIfExists('program_authorities');
        Schema::dropIfExists('import_batches');

        $this->dropExistingColumns('curriculum_versions', ['approval_reference', 'authority_date', 'effective_from', 'effective_until']);

        if (Schema::hasColumn('course_specifications', 'effective_term_id')) {
            Schema::table('course_specifications', function (Blueprint $table): void {
                $table->dropIndex('course_revision_effective_index');
                $table->dropConstrainedForeignId('effective_term_id');
            });
        }

        $this->dropExistingColumns('course_specifications', ['authority_reference', 'effective_from', 'effective_until']);

        if (Schema::hasColumn('course_requirements', 'effective_from')) {
            $today = now()->toDateString();
            $this->deduplicateCourseRequirementsBeforeTighteningUniqueKey($today);
            $this->deactivateOutOfPeriodCourseRequirements($today);

            Schema::table('course_requirements', function (Blueprint $table): void {
                $table->index('course_specification_id', 'course_requirements_specification_index');
            });

            Schema::table('course_requirements', function (Blueprint $table): void {
                $table->dropUnique('course_requirements_identity_unique');
            });

            $this->dropExistingColumns('course_requirements', ['equivalency_scope', 'required_outcome', 'effective_from', 'effective_until', 'authority']);

            Schema::table('course_requirements', function (Blueprint $table): void {
                $table->unique(['course_specification_id', 'rule_type', 'group_key', 'related_course_id'], 'course_requirements_identity_unique');
            });
        }

        $this->dropExistingColumns('course_requirements', ['direction', 'minimum_grade', 'accepts_transfer_credit']);

        $this->dropExistingColumns('external_competency_requirements', ['authority_reference', 'authority_date']);

        Schema::table('external_competency_requirements', function (Blueprint $table): void {
            $table->string('qualification_level', 128)->nullable()->change();
        });
    }

    /**
     * Keep the currently applicable ACTIVE rule first (then any other ACTIVE rule,
     * then newest effective_from, then highest id) per tightened identity key so
     * populated databases with historical/inactive/future duplicates preserve the
     * active prerequisite enforced at enrollment.
     */
    private function deduplicateCourseRequirementsBeforeTighteningUniqueKey(string $today): void
    {
        $rows = DB::table('course_requirements')
            ->select([
                'id',
                'course_specification_id',
                'rule_type',
                'group_key',
                'related_course_id',
                'state',
                'effective_from',
                'effective_until',
            ])
            ->get()
            ->sort(function (object $left, object $right) use ($today): int {
                $priorityCompare = $this->requirementDeduplicationPriority($left, $today)
                    <=> $this->requirementDeduplicationPriority($right, $today);

                if ($priorityCompare !== 0) {
                    return $priorityCompare;
                }

                $effectiveCompare = strcmp((string) ($right->effective_from ?? ''), (string) ($left->effective_from ?? ''));

                if ($effectiveCompare !== 0) {
                    return $effectiveCompare;
                }

                return ((int) $right->id) <=> ((int) $left->id);
            });

        $seen = [];
        $duplicateIds = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                (string) $row->course_specification_id,
                (string) $row->rule_type,
                (string) ($row->group_key ?? ''),
                (string) ($row->related_course_id ?? ''),
            ]);

            if (isset($seen[$key])) {
                $duplicateIds[] = (int) $row->id;
            } else {
                $seen[$key] = true;
            }
        }

        if ($duplicateIds !== []) {
            DB::table('course_requirements')->whereIn('id', $duplicateIds)->delete();
        }
    }

    /**
     * Deactivate surviving ACTIVE rules whose recorded effective window is strictly
     * in the future or already expired so dropping the date columns never promotes
     * future rules early or reactivates expired prerequisites.
     */
    private function deactivateOutOfPeriodCourseRequirements(string $today): void
    {
        DB::table('course_requirements')
            ->where('state', 'ACTIVE')
            ->where(function ($query) use ($today): void {
                $query->where('effective_from', '>', $today)
                    ->orWhere('effective_until', '<', $today);
            })
            ->update(['state' => 'INACTIVE']);
    }

    private function requirementDeduplicationPriority(object $row, string $today): int
    {
        if ((string) $row->state !== 'ACTIVE') {
            return 2;
        }

        $startsOk = $row->effective_from === null || (string) $row->effective_from <= $today;
        $endsOk = $row->effective_until === null || (string) $row->effective_until >= $today;

        return ($startsOk && $endsOk) ? 0 : 1;
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropExistingColumns(string $table, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));

        if ($existing !== []) {
            Schema::table($table, function (Blueprint $blueprint) use ($existing): void {
                $blueprint->dropColumn($existing);
            });
        }
    }

    public function down(): void
    {
        Schema::table('external_competency_requirements', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable();
            $table->date('authority_date')->nullable();
        });

        Schema::table('course_requirements', function (Blueprint $table): void {
            $table->dropUnique('course_requirements_identity_unique');
        });

        Schema::table('course_requirements', function (Blueprint $table): void {
            $table->string('direction', 32)->nullable();
            $table->decimal('minimum_grade', 7, 4)->nullable();
            $table->boolean('accepts_transfer_credit')->default(false);
            $table->string('equivalency_scope')->nullable();
            $table->string('required_outcome')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('authority')->default('');
            $table->unique(['course_specification_id', 'rule_type', 'group_key', 'related_course_id', 'effective_from'], 'course_requirements_identity_unique');
        });

        Schema::table('course_requirements', function (Blueprint $table): void {
            $table->dropIndex('course_requirements_specification_index');
        });

        Schema::table('course_specifications', function (Blueprint $table): void {
            $table->string('authority_reference', 255)->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('effective_term_id')->nullable()->constrained('terms')->restrictOnDelete();
            $table->index(['course_id', 'state', 'effective_from'], 'course_revision_effective_index');
        });

        Schema::table('curriculum_versions', function (Blueprint $table): void {
            $table->string('approval_reference')->nullable();
            $table->date('authority_date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
        });

        Schema::create('program_authorities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->string('authority_type', 64);
            $table->string('authority_reference', 255);
            $table->string('regulator', 128);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('curriculum_source_reference', 255)->nullable();
            $table->string('state', 32);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'authority_reference'], 'program_authority_reference_unique');
        });
    }
};
