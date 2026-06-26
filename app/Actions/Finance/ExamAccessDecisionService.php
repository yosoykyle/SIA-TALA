<?php

namespace App\Actions\Finance;

use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use Carbon\CarbonImmutable;

class ExamAccessDecisionService
{
    /**
     * @return array{allowed:bool,basis:string,accommodation_id:null}
     */
    public function decide(
        StudentProfile $studentProfile,
        Term $term,
        ?Enrollment $enrollment = null,
        ?CarbonImmutable $asOf = null,
    ): array {
        return ['allowed' => true, 'basis' => 'ra11984_finance_never_blocks_exams', 'accommodation_id' => null];
    }
}
