<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admission deadlines from the QA workflow: the cycle sets when physical papers and the enrollment payment are due
     * and when the admission period ends; the Registrar may extend an Applicant's own paper or payment deadline.
     */
    public function up(): void
    {
        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->dateTime('requirements_due_at')->nullable()->after('correction_closes_at');
            $table->dateTime('payment_due_at')->nullable()->after('requirements_due_at');
            $table->dateTime('period_ends_at')->nullable()->after('payment_due_at');
        });

        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->dateTime('requirements_due_at')->nullable();
            $table->dateTime('payment_due_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applicant_intakes', function (Blueprint $table) {
            $table->dropColumn(['requirements_due_at', 'payment_due_at']);
        });

        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->dropColumn(['requirements_due_at', 'payment_due_at', 'period_ends_at']);
        });
    }
};
