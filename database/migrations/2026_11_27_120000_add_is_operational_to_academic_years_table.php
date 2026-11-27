<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_years', function (Blueprint $table): void {
            $table->boolean('is_operational')
                ->default(true)
                ->after('state')
                ->index('academic_years_is_operational_idx');
        });
    }

    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table): void {
            $table->dropIndex('academic_years_is_operational_idx');
            $table->dropColumn('is_operational');
        });
    }
};
