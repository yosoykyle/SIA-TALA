<?php

namespace Tests\Feature\SystemAdministration;

use App\Actions\SystemAdministration\DisposalReviewRetirementGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class DisposalReviewRetirementGuardTest extends TestCase
{
    public function test_guard_refuses_non_empty_disposal_history_without_removing_it(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('test_tala_db', DB::connection()->getDatabaseName());

        Schema::dropIfExists('disposal_reviews');
        Schema::create('disposal_reviews', function (Blueprint $table): void {
            $table->id();
        });

        try {
            DB::table('disposal_reviews')->insert(['id' => 1]);

            try {
                app(DisposalReviewRetirementGuard::class)->assertEmpty();
                $this->fail('Non-empty disposal history must block retirement.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('approved disposition', $exception->getMessage());
            }

            $this->assertSame(1, DB::table('disposal_reviews')->count());
        } finally {
            Schema::dropIfExists('disposal_reviews');
        }
    }
}
