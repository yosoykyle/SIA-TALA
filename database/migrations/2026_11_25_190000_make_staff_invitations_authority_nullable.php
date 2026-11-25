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
        Schema::table('staff_invitations', function (Blueprint $table): void {
            $table->text('reason')->nullable()->change();
            $table->string('authority')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('staff_invitations')
            ->whereNull('reason')
            ->update(['reason' => 'Staff invitation recorded']);

        DB::table('staff_invitations')
            ->whereNull('authority')
            ->update(['authority' => 'System Administration']);

        Schema::table('staff_invitations', function (Blueprint $table): void {
            $table->text('reason')->nullable(false)->change();
            $table->string('authority')->nullable(false)->change();
        });
    }
};
