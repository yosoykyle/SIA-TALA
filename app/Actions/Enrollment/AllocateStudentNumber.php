<?php

namespace App\Actions\Enrollment;

use App\Models\StudentNumberSequence;
use Illuminate\Support\Facades\DB;

class AllocateStudentNumber
{
    public function execute(int $year): string
    {
        DB::table('student_number_sequences')->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sequence = StudentNumberSequence::query()->whereKey($year)->lockForUpdate()->firstOrFail();
        $sequence->update(['last_number' => $sequence->last_number + 1]);

        return sprintf('SIA-%d-%04d', $year, $sequence->last_number);
    }
}
