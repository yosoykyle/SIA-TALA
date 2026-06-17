<?php

namespace App\Actions\Finance;

use App\Models\Enrollment;
use App\Models\ExamAccessAccommodation;
use App\Models\LedgerEntry;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Support\DecimalMoney;
use Carbon\CarbonImmutable;

class ExamAccessDecisionService
{
    public function __construct(private readonly DecimalMoney $money) {}

    /**
     * @return array{allowed:bool,basis:string,accommodation_id:int|null}
     */
    public function decide(
        StudentProfile $studentProfile,
        Term $term,
        ?Enrollment $enrollment = null,
        ?CarbonImmutable $asOf = null,
    ): array {
        $date = ($asOf ?? CarbonImmutable::now(config('app.timezone')))->toDateString();

        if ($this->money->isZeroOrNegative($this->balanceFor($studentProfile, $enrollment))) {
            return ['allowed' => true, 'basis' => 'fully_paid', 'accommodation_id' => null];
        }

        $accommodation = ExamAccessAccommodation::query()
            ->where('student_profile_id', $studentProfile->id)
            ->where('status', ExamAccessAccommodation::StatusApproved)
            ->whereDate('valid_from', '<=', $date)
            ->whereDate('valid_until', '>=', $date)
            ->where(function ($query) use ($term): void {
                $query->where(function ($termScope) use ($term): void {
                    $termScope->where('scope', ExamAccessAccommodation::ScopeTerm)
                        ->where('term_id', $term->id);
                });

                if ($term->academic_year_id !== null) {
                    $query->orWhere(function ($yearScope) use ($term): void {
                        $yearScope->where('scope', ExamAccessAccommodation::ScopeAcademicYear)
                            ->where('academic_year_id', $term->academic_year_id);
                    });
                }
            })
            ->latest('reviewed_at')
            ->first();

        if ($accommodation instanceof ExamAccessAccommodation) {
            return [
                'allowed' => true,
                'basis' => $accommodation->basis,
                'accommodation_id' => $accommodation->id,
            ];
        }

        return ['allowed' => false, 'basis' => 'outstanding_balance', 'accommodation_id' => null];
    }

    private function balanceFor(StudentProfile $studentProfile, ?Enrollment $enrollment): string
    {
        if ($enrollment instanceof Enrollment) {
            $runningBalance = LedgerEntry::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereNotNull('running_balance')
                ->latest('id')
                ->value('running_balance');

            if ($runningBalance !== null) {
                return $this->money->normalize((string) $runningBalance);
            }
        }

        return $this->money->normalize((string) $studentProfile->current_balance);
    }
}
