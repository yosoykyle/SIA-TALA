<?php

namespace App\Actions\Finance;

use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FinanceExport;
use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use App\Models\RegistrationProposalVersion;
use App\Support\DecimalMoney;
use App\Support\DisplayDateTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Throwable;

class AccountingDisplay
{
    public static function money(mixed $amount, string $fallback = 'Not recorded', bool $withCurrency = true): string
    {
        if ($amount === null || $amount === '') {
            return $fallback;
        }

        $formatted = number_format((float) $amount, 2);

        return $withCurrency ? 'PHP '.$formatted : $formatted;
    }

    public static function cents(int $cents, bool $withCurrency = true): string
    {
        return self::money(app(DecimalMoney::class)->fromCents($cents), withCurrency: $withCurrency);
    }

    public static function dateTime(mixed $value, string $format = 'M j, Y · g:i A', string $fallback = 'Not recorded'): string
    {
        if ($value instanceof DateTimeInterface) {
            return DisplayDateTime::format($value, $format, $fallback);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return DisplayDateTime::format(CarbonImmutable::parse($value), $format, $fallback);
            } catch (Throwable) {
                return $fallback;
            }
        }

        return $fallback;
    }

    public static function date(mixed $value, string $fallback = 'Not recorded'): string
    {
        return self::dateTime($value, 'M j, Y', $fallback);
    }

    public static function assessmentBasisLabel(?string $basis): string
    {
        return match ($basis) {
            'PublishedFeePlan' => 'Standard fee plan',
            'AuthorizedIndividualAssessment' => 'Custom assessment',
            null, '' => 'Not recorded',
            default => Str::headline($basis),
        };
    }

    public static function channelLabel(?string $channel): string
    {
        return match ($channel) {
            'e_wallet' => 'E-Wallet',
            'gcash', 'gcash_manual' => 'GCash',
            'bank_transfer' => 'Bank Transfer',
            'cash' => 'Cash (Campus Cashier)',
            null, '' => 'Not recorded',
            default => Str::of($channel)->replace('_', ' ')->headline()->toString(),
        };
    }

    /**
     * Format a channel label enriched with the destination account snapshot when recorded.
     *
     * @param  array<string, mixed>|null  $channelSnapshot
     */
    public static function channelDetailLabel(?string $channel, ?array $channelSnapshot = null): string
    {
        $baseLabel = self::channelLabel($channel);

        if (! is_array($channelSnapshot) || $channelSnapshot === []) {
            return $baseLabel;
        }

        $group = $channelSnapshot['group'] ?? null;
        $groupLabel = match ($group) {
            'bank_transfer' => 'Bank Transfer',
            'e_wallets' => 'E-Wallet',
            default => $baseLabel,
        };

        $providerOrBank = trim((string) ($channelSnapshot['provider_or_bank'] ?? ''));
        $accountNumber = trim((string) ($channelSnapshot['account_number'] ?? ''));

        if ($providerOrBank !== '' && $accountNumber !== '') {
            return "{$groupLabel} — {$providerOrBank} ({$accountNumber})";
        }

        if ($providerOrBank !== '') {
            return "{$groupLabel} — {$providerOrBank}";
        }

        if ($accountNumber !== '') {
            return "{$groupLabel} ({$accountNumber})";
        }

        return $baseLabel;
    }

    public static function assessmentStateLabel(?string $state): string
    {
        return match ($state) {
            Assessment::StateActive => 'Current',
            Assessment::StateDraft => 'Draft',
            Assessment::StatePendingReview => 'Pending review',
            Assessment::StateSuperseded => 'Superseded',
            Assessment::StateCancelled => 'Cancelled',
            Assessment::StateLocked => 'Locked',
            null, '' => 'Unavailable',
            default => Str::headline($state),
        };
    }

    public static function coverageCategoryLabel(?string $category): string
    {
        return match ($category) {
            ApprovedCoverage::CategoryScholarship => 'Scholarship',
            ApprovedCoverage::CategorySponsorship => 'Sponsorship',
            ApprovedCoverage::CategoryGovernmentSubsidy => 'Government subsidy',
            ApprovedCoverage::CategoryOtherAuthorizedFunding => 'Other authorized funding',
            null, '' => 'Not recorded',
            default => Str::headline($category),
        };
    }

    public static function coverageStateLabel(?string $state): string
    {
        return match ($state) {
            ApprovedCoverage::StateApplied => 'Applied',
            ApprovedCoverage::StateSuperseded => 'Superseded',
            ApprovedCoverage::StateReversed => 'Reversed',
            null, '' => 'Unavailable',
            default => Str::headline($state),
        };
    }

    public static function proposalStateLabel(?string $state): string
    {
        return match ($state) {
            RegistrationProposalVersion::StateDraft => 'Draft',
            RegistrationProposalVersion::StateIssued => 'Issued',
            RegistrationProposalVersion::StateConfirmed => 'Confirmed',
            RegistrationProposalVersion::StateApplied => 'Applied',
            RegistrationProposalVersion::StateSuperseded => 'Superseded',
            RegistrationProposalVersion::StateCancelled => 'Cancelled',
            null, '' => 'Unavailable',
            default => Str::headline($state),
        };
    }

    public static function financeExportOutcomeLabel(?string $outcome): string
    {
        return match ($outcome) {
            FinanceExport::OutcomePreparing => 'Preparing',
            FinanceExport::OutcomeGenerated => 'Generated',
            FinanceExport::OutcomeNoRows => 'No matching rows',
            FinanceExport::OutcomeFailed => 'Failed',
            null, '' => 'Unavailable',
            default => Str::headline($outcome),
        };
    }

    public static function evidenceStateLabel(?string $state): string
    {
        return match ($state) {
            PaymentEvidenceVersion::StateSubmitted => 'Submitted',
            PaymentEvidenceVersion::StateVerified => 'Verified',
            PaymentEvidenceVersion::StateRejected => 'Rejected',
            null, '' => 'Unavailable',
            default => Str::headline($state),
        };
    }

    public static function feePlanStatusLabel(string $state): string
    {
        return match ($state) {
            FeePlan::StatePublished => 'Current plan',
            FeePlan::StateDraft => 'Draft',
            FeePlan::StateSuperseded => 'Superseded plan',
            default => Str::headline($state),
        };
    }

    public static function feePlanStatusPluralLabel(string $state): string
    {
        return match ($state) {
            FeePlan::StatePublished => 'Current plans',
            FeePlan::StateDraft => 'Drafts',
            FeePlan::StateSuperseded => 'Superseded plans',
            default => Str::headline($state),
        };
    }

    public static function feePlanStatusColor(string $state): string
    {
        return match ($state) {
            FeePlan::StatePublished => 'success',
            FeePlan::StateDraft => 'warning',
            default => 'gray',
        };
    }

    public static function enrollmentOutcomeLabel(?string $outcome): string
    {
        return match ($outcome) {
            Enrollment::OutcomeInProgress => 'In progress',
            Enrollment::OutcomeOfficiallyEnrolled => 'Officially enrolled',
            Enrollment::OutcomeNotEnrolled => 'Not enrolled',
            Enrollment::OutcomeCancelledByLearner => 'Cancelled by student',
            Enrollment::OutcomeCancelledByRegistrar => 'Cancelled by Registrar',
            null, '' => 'Unavailable',
            default => Str::headline($outcome),
        };
    }

    public static function isVerifiedPayment(Payment $payment): bool
    {
        return $payment->state === Payment::StatePosted
            && $payment->evidence_status === 'verified'
            && $payment->verified_at !== null;
    }

    /** @return array{label: string, color: string} */
    public static function obligationStatus(array $obligation): array
    {
        if ((float) ($obligation['balance'] ?? 0) === 0.0) {
            return ['label' => 'Paid or covered', 'color' => 'success'];
        }

        if ((bool) ($obligation['is_due'] ?? false)) {
            return ['label' => 'Due now', 'color' => 'warning'];
        }

        return ['label' => 'Upcoming', 'color' => 'gray'];
    }

    public static function learnerName(?Enrollment $case, string $fallback = 'Student identity unavailable'): string
    {
        return $case?->credentialUser?->getFilamentName() ?? $fallback;
    }

    public static function learnerIdentifier(?Enrollment $case, string $fallback = 'Number not assigned'): string
    {
        return $case?->studentProfile?->student_number
            ?? $case?->admissionApplication?->application_reference
            ?? $case?->case_reference
            ?? $fallback;
    }

    public static function learnerProgram(?Enrollment $case, string $fallback = 'Program unavailable'): string
    {
        return $case?->studentProfile?->program?->name
            ?? $case?->admissionApplication?->program?->name
            ?? $fallback;
    }

    public static function learnerInitials(?Enrollment $case): ?string
    {
        $student = $case?->studentProfile;

        if ($student === null) {
            return null;
        }

        return mb_substr($student->first_name, 0, 1).mb_substr($student->last_name, 0, 1);
    }
}
