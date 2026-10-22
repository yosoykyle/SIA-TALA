<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_late_authorities', function (Blueprint $table) {
            $table->text('reason')->nullable()->change();
            $table->timestamp('effective_at')->nullable()->change();
            $table->string('source_academic_decision', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('registration_late_authorities')->whereNull('reason')->orWhereNull('effective_at')->orWhereNull('source_academic_decision')->exists()) {
            throw new RuntimeException('Cannot require optional approval details while records without those details exist.');
        }
        Schema::table('registration_late_authorities', function (Blueprint $table) {
            $table->text('reason')->nullable(false)->change();
            $table->timestamp('effective_at')->nullable(false)->change();
            $table->string('source_academic_decision', 255)->nullable(false)->change();
        });
    }
};
