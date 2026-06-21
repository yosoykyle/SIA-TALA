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
        DB::table('permissions')
            ->where('name', 'request-documents')
            ->update(['name' => 'submit-service-requests']);

        DB::table('permissions')
            ->where('name', 'manage-document-requests')
            ->update(['name' => 'manage-service-requests']);

        Schema::dropIfExists('document_requests');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type')->index();
            $table->string('status')->default('pending')->index();
            $table->boolean('is_free_request')->default(false);
            $table->boolean('delivery_consent')->default(false);
            $table->string('delivery_mode')->default('pickup')->index();
            $table->string('courier_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->string('tracking_number_normalized')->nullable();
            $table->decimal('shipping_fee', 12, 2)->nullable();
            $table->string('courier_receipt_path', 500)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('shipping_grace_ends_at')->nullable();
            $table->foreignId('shipping_fee_assessment_transaction_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->foreignId('shipping_fee_payment_transaction_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_profile_id', 'status']);
            $table->index(['status', 'shipped_at']);
        });

        DB::table('permissions')
            ->where('name', 'submit-service-requests')
            ->update(['name' => 'request-documents']);

        DB::table('permissions')
            ->where('name', 'manage-service-requests')
            ->update(['name' => 'manage-document-requests']);
    }
};
