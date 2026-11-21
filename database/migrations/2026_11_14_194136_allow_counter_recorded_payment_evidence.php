<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_evidence_versions', function (Blueprint $table): void {
            $table->string('disk', 64)->nullable()->change();
            $table->string('path')->nullable()->change();
            $table->string('original_name')->nullable()->change();
            $table->string('mime_type', 100)->nullable()->change();
            $table->unsignedBigInteger('size_bytes')->nullable()->change();
            $table->string('source', 24)->default('applicant_upload')->after('submitted_at');
        });
    }

    public function down(): void
    {
        if (DB::table('payment_evidence_versions')->where('source', 'accounting_counter')->exists()) {
            throw new RuntimeException('Accounting-recorded counter payment evidence must be preserved before reverting this migration.');
        }

        Schema::table('payment_evidence_versions', function (Blueprint $table): void {
            $table->dropColumn('source');
            $table->string('disk', 64)->default('local')->change();
            $table->string('path')->change();
            $table->string('original_name')->change();
            $table->string('mime_type', 100)->change();
            $table->unsignedBigInteger('size_bytes')->change();
        });
    }
};
