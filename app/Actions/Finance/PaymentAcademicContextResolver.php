<?php

namespace App\Actions\Finance;

use App\Actions\Enrollment\EnrollmentAcademicContextResolver;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\TermAccount;
use Illuminate\Container\Attributes\Scoped;

#[Scoped]
final class PaymentAcademicContextResolver
{
    /** @var array<int, Enrollment|null> */
    private array $enrollments = [];

    /** @var array<int, array<string, mixed>> */
    private array $contexts = [];

    public function __construct(
        private readonly EnrollmentAcademicContextResolver $academicContextResolver,
    ) {}

    public function enrollment(Payment $payment): ?Enrollment
    {
        $cacheKey = $this->cacheKey($payment);

        if (array_key_exists($cacheKey, $this->enrollments)) {
            return $this->enrollments[$cacheKey];
        }

        $profile = $payment->relationLoaded('studentProfile')
            ? $payment->studentProfile
            : null;

        if ($profile instanceof StudentProfile && $profile->relationLoaded('enrollments')) {
            $enrollment = $profile->enrollments
                ->firstWhere('term_id', $payment->term_id);

            if ($enrollment instanceof Enrollment) {
                $enrollment->setRelation('studentProfile', $profile);

                return $this->enrollments[$cacheKey] = $enrollment;
            }
        }

        $termAccount = $payment->relationLoaded('termAccount')
            ? $payment->termAccount
            : ($payment->term_account_id !== null ? $payment->termAccount()->with('enrollment')->first() : null);

        if ($termAccount instanceof TermAccount && $termAccount->enrollment instanceof Enrollment) {
            if ($profile instanceof StudentProfile) {
                $termAccount->enrollment->setRelation('studentProfile', $profile);
            }

            return $this->enrollments[$cacheKey] = $termAccount->enrollment;
        }

        if ($payment->student_profile_id === null || $payment->term_id === null) {
            return $this->enrollments[$cacheKey] = null;
        }

        return $this->enrollments[$cacheKey] = Enrollment::query()
            ->where('student_profile_id', $payment->student_profile_id)
            ->where('term_id', $payment->term_id)
            ->first();
    }

    /** @return array<string, mixed> */
    public function forPayment(Payment $payment): array
    {
        $cacheKey = $this->cacheKey($payment);

        if (isset($this->contexts[$cacheKey])) {
            return $this->contexts[$cacheKey];
        }

        $enrollment = $this->enrollment($payment);

        return $this->contexts[$cacheKey] = $enrollment instanceof Enrollment
            ? $this->academicContextResolver->forEnrollment($enrollment)
            : [];
    }

    private function cacheKey(Payment $payment): int
    {
        return $payment->getKey() !== null
            ? (int) $payment->getKey()
            : -spl_object_id($payment);
    }
}
