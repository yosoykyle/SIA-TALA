<?php

namespace App\Actions\Enrollment;

use App\Models\StudentNumberSequence;
use App\Models\StudentProfile;
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
        $nextNumber = (int) $sequence->last_number;

        do {
            $nextNumber++;
            $candidate = sprintf('SIA-%d-%04d', $year, $nextNumber);
        } while (StudentProfile::query()->where('student_number', $candidate)->exists());

        $sequence->update(['last_number' => $nextNumber]);

        return $candidate;
    }

    /**
     * Atomically advance the year sequence when an imported student number matches `SIA-YYYY-NNNN`.
     */
    public function syncSequenceForImportedNumber(string $studentNumber): void
    {
        if (! preg_match('/^SIA-(\d{4})-(\d+)$/i', trim($studentNumber), $matches)) {
            return;
        }

        $year = (int) $matches[1];
        $seq = (int) $matches[2];

        if ($year < 2000 || $seq <= 0) {
            return;
        }

        DB::table('student_number_sequences')->insertOrIgnore([
            'year' => $year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = StudentNumberSequence::query()->whereKey($year)->lockForUpdate()->firstOrFail();

        if ($seq > (int) $sequence->last_number) {
            $sequence->update(['last_number' => $seq]);
        }
    }
}
