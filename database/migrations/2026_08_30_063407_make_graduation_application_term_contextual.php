<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('graduation_applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('term_id')->nullable()->change();
        });
        Schema::table('student_lifecycle_changes', function (Blueprint $table): void {
            $table->unsignedBigInteger('term_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('graduation_applications')->whereNull('term_id')->exists()
            || DB::table('student_lifecycle_changes')->whereNull('term_id')->exists()) {
            throw new RuntimeException('Cannot restore required completion Terms while contextual applications or lifecycle evidence without a Term exist.');
        }

        Schema::table('student_lifecycle_changes', function (Blueprint $table): void {
            $table->unsignedBigInteger('term_id')->nullable(false)->change();
        });
        Schema::table('graduation_applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('term_id')->nullable(false)->change();
        });
    }
};
