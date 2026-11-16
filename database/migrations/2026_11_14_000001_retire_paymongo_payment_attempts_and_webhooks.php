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
        Schema::dropIfExists('payment_attempt_obligations');

        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'payment_attempt_id')) {
            DB::table('payments')
                ->whereNotNull('payment_attempt_id')
                ->update(['payment_attempt_id' => null]);

            $foreignKeys = collect(Schema::getForeignKeys('payments'))
                ->filter(fn (array $foreignKey): bool => in_array('payment_attempt_id', $foreignKey['columns'] ?? [], true))
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
                ->values()
                ->all();

            $indexes = collect(Schema::getIndexes('payments'))
                ->filter(fn (array $index): bool => in_array('payment_attempt_id', $index['columns'] ?? [], true))
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
                ->values()
                ->all();

            $driver = Schema::getConnection()->getDriverName();

            Schema::table('payments', function (Blueprint $table) use ($driver, $foreignKeys, $indexes): void {
                if ($driver !== 'sqlite') {
                    foreach ($foreignKeys as $foreignKey) {
                        $table->dropForeign($foreignKey);
                    }
                }

                foreach ($indexes as $index) {
                    $table->dropIndex($index);
                }

                $table->dropColumn('payment_attempt_id');
            });
        }

        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('webhook_calls');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-only retirement of PayMongo payment attempts and webhook tables.
    }
};
