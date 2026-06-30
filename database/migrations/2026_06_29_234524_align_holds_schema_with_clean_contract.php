<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('holds', 'type') && ! Schema::hasColumn('holds', 'hold_type')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->renameColumn('type', 'hold_type');
            });
        }

        if (Schema::hasColumn('holds', 'staff_reason') && ! Schema::hasColumn('holds', 'reason')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->renameColumn('staff_reason', 'reason');
            });
        }

        if (Schema::hasColumn('holds', 'student_reason') && ! Schema::hasColumn('holds', 'student_message')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->renameColumn('student_reason', 'student_message');
            });
        }

        if (! Schema::hasColumn('holds', 'staff_only_reason')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->text('staff_only_reason')->nullable()->after('reason');
            });
        }

        if (! Schema::hasColumn('holds', 'resolution_requirement')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->text('resolution_requirement')->nullable()->after('expires_at');
            });
        }

        if (! Schema::hasColumn('holds', 'created_by')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->after('source_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        Schema::table('holds', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
            $table->string('source_type')->nullable()->change();
            $table->timestamp('effective_at')->nullable()->change();
        });

        if (! Schema::hasIndex('holds', 'holds_hold_type_index')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->index('hold_type');
            });
        }

        if (! Schema::hasIndex('holds', 'holds_blocking_level_index')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->index('blocking_level');
            });
        }

        if (! Schema::hasIndex('holds', 'holds_status_index')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->index('status');
            });
        }

        if (! Schema::hasIndex('holds', 'holds_student_enrollment_status_index')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->index(
                    ['student_profile_id', 'enrollment_id', 'status'],
                    'holds_student_enrollment_status_index',
                );
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('holds', 'hold_type') && ! Schema::hasColumn('holds', 'type')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->renameColumn('hold_type', 'type');
            });
        }

        if (Schema::hasColumn('holds', 'reason') && ! Schema::hasColumn('holds', 'staff_reason')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->renameColumn('reason', 'staff_reason');
            });
        }

        if (Schema::hasColumn('holds', 'student_message') && ! Schema::hasColumn('holds', 'student_reason')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->renameColumn('student_message', 'student_reason');
            });
        }
    }
};
