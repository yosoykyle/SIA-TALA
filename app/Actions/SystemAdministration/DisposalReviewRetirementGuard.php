<?php

namespace App\Actions\SystemAdministration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DisposalReviewRetirementGuard
{
    public function assertEmpty(): void
    {
        if (Schema::hasTable('disposal_reviews') && DB::table('disposal_reviews')->exists()) {
            throw new RuntimeException('The disposal_reviews table contains historical records and requires an approved disposition before removal.');
        }
    }
}
