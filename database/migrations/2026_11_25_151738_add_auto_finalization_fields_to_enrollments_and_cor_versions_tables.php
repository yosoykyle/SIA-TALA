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
        if (! Schema::hasColumn('enrollments', 'finalization_mode')) {
            Schema::table('enrollments', function (Blueprint $table): void {
                $table->string('finalization_mode', 20)
                    ->default('manual')
                    ->after('finalized_by');
            });
        }

        if (Schema::hasColumn('cor_versions', 'issued_by')) {
            Schema::table('cor_versions', function (Blueprint $table): void {
                $table->unsignedBigInteger('issued_by')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('enrollments', 'finalization_mode')) {
            Schema::table('enrollments', function (Blueprint $table): void {
                $table->dropColumn('finalization_mode');
            });
        }
    }
};
