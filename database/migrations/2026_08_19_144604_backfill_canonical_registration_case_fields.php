<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('enrollments')
            ->whereNull('credential_user_id')
            ->orderBy('id')
            ->chunkById(100, function ($enrollments): void {
                foreach ($enrollments as $enrollment) {
                    $profile = DB::table('student_profiles')->where('id', $enrollment->student_profile_id)->first();

                    if ($profile === null || $profile->user_id === null) {
                        throw new RuntimeException("Enrollment {$enrollment->id} cannot be mapped to one credential owner.");
                    }

                    $selectionBasis = match ($enrollment->student_type) {
                        'regular' => 'StandardCurriculum',
                        'irregular' => 'IndividuallyAdvised',
                        default => null,
                    };

                    if ($selectionBasis === null) {
                        throw new RuntimeException("Enrollment {$enrollment->id} has an ambiguous legacy student_type.");
                    }

                    $canonicalOutcome = match ($enrollment->status) {
                        'officially_enrolled' => 'OfficiallyEnrolled',
                        'cancelled' => 'Cancelled',
                        'pending', 'pre_enrolled', 'pending_review', 'pending_payment', 'capacity_pending' => 'InProgress',
                        default => null,
                    };

                    if ($canonicalOutcome === null) {
                        throw new RuntimeException("Enrollment {$enrollment->id} has an ambiguous legacy status.");
                    }

                    DB::table('enrollments')->where('id', $enrollment->id)->update([
                        'credential_user_id' => $profile->user_id,
                        'admission_application_id' => $profile->applicant_intake_id,
                        'case_reference' => sprintf('REG-LEGACY-%08d', $enrollment->id),
                        'selection_basis' => $selectionBasis,
                        'canonical_outcome' => $canonicalOutcome,
                        'started_at' => $enrollment->created_at,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Forward-fix migration: never erase later canonical values.
    }
};
