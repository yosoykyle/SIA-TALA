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
        if (! Schema::hasColumn('enrollments', 'credential_user_id')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table->foreignId('credential_user_id')->nullable()->after('id')->constrained('users')->restrictOnDelete();
                $table->foreignId('admission_application_id')->nullable()->after('credential_user_id')->constrained('applicant_intakes')->restrictOnDelete();
                $table->string('case_reference', 40)->nullable()->after('admission_application_id');
                $table->string('selection_basis', 40)->nullable()->after('student_type');
                $table->string('canonical_outcome', 40)->nullable()->after('selection_basis');
                $table->unsignedBigInteger('current_proposal_version_id')->nullable()->after('canonical_outcome');
                $table->unsignedBigInteger('current_cor_version_id')->nullable()->after('current_proposal_version_id');
                $table->foreignId('started_by')->nullable()->after('current_cor_version_id')->constrained('users')->nullOnDelete();
                $table->string('start_method', 24)->nullable()->after('started_by');
                $table->timestamp('started_at')->nullable()->after('start_method');
                $table->foreignId('finalized_by')->nullable()->after('officially_enrolled_at')->constrained('users')->nullOnDelete();
                $table->unsignedInteger('lock_version')->default(1);

                $table->unique('case_reference', 'registration_cases_reference_unique');
                $table->unique(['credential_user_id', 'term_id'], 'registration_cases_user_term_unique');
                $table->index(['term_id', 'canonical_outcome', 'updated_at'], 'registration_cases_queue_index');
                $table->index(['admission_application_id', 'term_id'], 'registration_cases_application_term_index');
            });

            Schema::table('enrollments', function (Blueprint $table) {
                $table->foreignId('student_profile_id')->nullable()->change();
                $table->string('student_type')->nullable()->change();
            });
        }

        if (! Schema::hasColumn('course_enrollments', 'section_id')) {
            Schema::table('course_enrollments', function (Blueprint $table) {
                $table->foreignId('section_id')->nullable()->after('term_offering_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('registration_proposal_item_id')->nullable()->after('section_id');
                $table->foreignId('published_timetable_version_id')->nullable()->after('registration_proposal_item_id')->constrained()->restrictOnDelete();
                $table->foreignId('supersedes_course_enrollment_id')->nullable()->after('published_timetable_version_id');
                $table->string('change_source', 40)->nullable()->after('supersedes_course_enrollment_id');
                $table->timestamp('effective_from')->nullable()->after('change_source');
                $table->timestamp('effective_until')->nullable()->after('effective_from');
                $table->boolean('is_current')->default(false)->after('effective_until');

                $table->foreign('supersedes_course_enrollment_id', 'course_registration_supersedes_fk')
                    ->references('id')->on('course_enrollments')->restrictOnDelete();
                $table->index(['enrollment_id', 'is_current', 'status'], 'official_course_registration_current_index');
                $table->index(['section_id', 'is_current'], 'official_course_registration_section_index');
            });
        }

        if (! Schema::hasColumn('enrollment_seat_reservations', 'registration_proposal_item_id')) {
            Schema::table('enrollment_seat_reservations', function (Blueprint $table) {
                $table->unsignedBigInteger('registration_proposal_item_id')->nullable()->after('enrollment_id');
            });
        }

        if (! Schema::hasColumn('enrollment_seat_reservations', 'published_timetable_version_id')) {
            Schema::table('enrollment_seat_reservations', function (Blueprint $table) {
                $table->unsignedBigInteger('published_timetable_version_id')->nullable()->after('section_id');
            });
        }

        Schema::table('enrollment_seat_reservations', function (Blueprint $table) {
            $table->foreign('published_timetable_version_id', 'seat_reservation_timetable_version_fk')
                ->references('id')->on('published_timetable_versions')->restrictOnDelete();
        });

        Schema::table('enrollment_seat_reservations', function (Blueprint $table) {
            $table->foreignId('course_enrollment_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollment_seat_reservations', function (Blueprint $table) {
            $table->dropForeign('seat_reservation_timetable_version_fk');
            $table->dropColumn('published_timetable_version_id');
            $table->dropColumn('registration_proposal_item_id');
        });

        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropForeign('course_registration_supersedes_fk');
            $table->dropConstrainedForeignId('published_timetable_version_id');
            $table->dropConstrainedForeignId('section_id');
            $table->dropIndex('official_course_registration_current_index');
            $table->dropIndex('official_course_registration_section_index');
            $table->dropColumn([
                'registration_proposal_item_id', 'supersedes_course_enrollment_id', 'change_source',
                'effective_from', 'effective_until', 'is_current',
            ]);
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('registration_cases_reference_unique');
            $table->dropUnique('registration_cases_user_term_unique');
            $table->dropIndex('registration_cases_queue_index');
            $table->dropIndex('registration_cases_application_term_index');
            $table->dropConstrainedForeignId('credential_user_id');
            $table->dropConstrainedForeignId('admission_application_id');
            $table->dropConstrainedForeignId('started_by');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn([
                'case_reference', 'selection_basis', 'canonical_outcome', 'current_proposal_version_id',
                'current_cor_version_id', 'start_method', 'started_at', 'lock_version',
            ]);
        });
    }
};
