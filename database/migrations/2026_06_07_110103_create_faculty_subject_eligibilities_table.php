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
        if (Schema::hasTable('faculty_subject_eligibilities')) {
            $this->repairPartiallyCreatedTable();

            return;
        }

        Schema::create('faculty_subject_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default('active');
            $table->unsignedSmallInteger('priority')->nullable();
            $table->decimal('max_weekly_hours', 5, 2)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('term_scope_id')->storedAs('coalesce(term_id, 0)');
            $table->timestamps();

            $table->unique(
                ['faculty_id', 'subject_id', 'term_scope_id', 'status'],
                'faculty_subject_eligibility_unique'
            );
            $table->index(['faculty_id', 'status']);
            $table->index(['subject_id', 'status']);
            $table->index(['term_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_subject_eligibilities');
    }

    private function repairPartiallyCreatedTable(): void
    {
        if (! Schema::hasColumn('faculty_subject_eligibilities', 'term_scope_id')) {
            Schema::table('faculty_subject_eligibilities', function (Blueprint $table) {
                $table->unsignedBigInteger('term_scope_id')->storedAs('coalesce(term_id, 0)')->after('approved_at');
            });
        }

        if (! $this->foreignKeyExists('faculty_subject_eligibilities', 'faculty_subject_eligibilities_term_id_foreign')) {
            Schema::table('faculty_subject_eligibilities', function (Blueprint $table) {
                $table->foreign('term_id')
                    ->references('id')
                    ->on('terms')
                    ->restrictOnDelete();
            });
        }

        if (! $this->foreignKeyExists('faculty_subject_eligibilities', 'faculty_subject_eligibilities_approved_by_foreign')) {
            Schema::table('faculty_subject_eligibilities', function (Blueprint $table) {
                $table->foreign('approved_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        if (! $this->indexExists('faculty_subject_eligibilities', 'faculty_subject_eligibility_unique')) {
            Schema::table('faculty_subject_eligibilities', function (Blueprint $table) {
                $table->unique(
                    ['faculty_id', 'subject_id', 'term_scope_id', 'status'],
                    'faculty_subject_eligibility_unique'
                );
            });
        }

        if (! $this->indexExists('faculty_subject_eligibilities', 'faculty_subject_eligibilities_faculty_id_status_index')) {
            Schema::table('faculty_subject_eligibilities', function (Blueprint $table) {
                $table->index(['faculty_id', 'status']);
            });
        }

        if (! $this->indexExists('faculty_subject_eligibilities', 'faculty_subject_eligibilities_subject_id_status_index')) {
            Schema::table('faculty_subject_eligibilities', function (Blueprint $table) {
                $table->index(['subject_id', 'status']);
            });
        }

        if (! $this->indexExists('faculty_subject_eligibilities', 'faculty_subject_eligibilities_term_id_status_index')) {
            Schema::table('faculty_subject_eligibilities', function (Blueprint $table) {
                $table->index(['term_id', 'status']);
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
