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
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->foreignId('proposed_section_id')
                ->nullable()
                ->after('term_offering_id')
                ->constrained('sections')
                ->nullOnDelete();
            $table->timestamp('proposed_at')->nullable()->after('proposed_section_id');
            $table->index(['proposed_section_id', 'status'], 'course_enrollments_proposal_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropIndex('course_enrollments_proposal_index');
            $table->dropConstrainedForeignId('proposed_section_id');
            $table->dropColumn('proposed_at');
        });
    }
};
