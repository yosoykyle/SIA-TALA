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
        Schema::table('grade_corrections', function (Blueprint $table) {
            $table->string('academic_head_review_status')->nullable()->after('assigned_to')->index();
            $table->foreignId('academic_head_reviewed_by')->nullable()->after('academic_head_review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('academic_head_reviewed_at')->nullable()->after('academic_head_reviewed_by');
            $table->string('academic_head_review_note', 500)->nullable()->after('academic_head_reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('grade_corrections', function (Blueprint $table) {
            $table->dropForeign(['academic_head_reviewed_by']);
            $table->dropColumn([
                'academic_head_review_status',
                'academic_head_reviewed_by',
                'academic_head_reviewed_at',
                'academic_head_review_note',
            ]);
        });
    }
};
