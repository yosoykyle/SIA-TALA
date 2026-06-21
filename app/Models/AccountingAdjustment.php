<?php

namespace App\Models;

use Database\Factories\AccountingAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingAdjustment extends Model
{
    /** @use HasFactory<AccountingAdjustmentFactory> */
    use HasFactory;

    public const TypeStudentAccountDebit = 'student_account_debit';

    public const TypeStudentAccountCredit = 'student_account_credit';

    public const TypeLedgerEntryReversal = 'ledger_entry_reversal';

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TypeStudentAccountDebit => 'Student Account Debit',
            self::TypeStudentAccountCredit => 'Student Account Credit',
            self::TypeLedgerEntryReversal => 'Ledger Entry Reversal',
        ];
    }

    /**
     * @return list<string>
     */
    public static function reversibleEntryTypes(): array
    {
        return [
            'assessment',
            'discount',
            'installment_penalty',
            'penalty',
            'payment',
        ];
    }

    public static function studentOptionLabel(StudentProfile $studentProfile): string
    {
        return collect([
            $studentProfile->student_id,
            $studentProfile->user?->name,
            $studentProfile->program?->code,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }

    /**
     * @return array<int, string>
     */
    public static function enrollmentOptionsFor(mixed $studentProfileId, mixed $termId): array
    {
        if (blank($studentProfileId)) {
            return [];
        }

        return Enrollment::query()
            ->with(['section', 'sectionDeliveryGroup'])
            ->where('student_profile_id', (int) $studentProfileId)
            ->when(filled($termId), fn ($query) => $query->where('term_id', (int) $termId))
            ->latest('id')
            ->get()
            ->mapWithKeys(fn (Enrollment $enrollment): array => [
                $enrollment->id => self::enrollmentOptionLabel($enrollment),
            ])
            ->all();
    }

    public static function enrollmentOptionLabel(Enrollment $enrollment): string
    {
        return collect([
            "Enrollment #{$enrollment->id}",
            $enrollment->status,
            $enrollment->year_level,
            $enrollment->section?->name,
            $enrollment->sectionDeliveryGroup?->name,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }

    /**
     * @return array<int, string>
     */
    public static function sourceLedgerOptionsFor(mixed $studentProfileId, mixed $termId, mixed $enrollmentId): array
    {
        if (blank($studentProfileId)) {
            return [];
        }

        return LedgerEntry::query()
            ->where('student_profile_id', (int) $studentProfileId)
            ->when(filled($termId), fn ($query) => $query->where('term_id', (int) $termId))
            ->when(filled($enrollmentId), fn ($query) => $query->where('enrollment_id', (int) $enrollmentId))
            ->whereIn('entry_type', self::reversibleEntryTypes())
            ->latest('posted_at')
            ->latest('id')
            ->get()
            ->mapWithKeys(fn (LedgerEntry $ledgerEntry): array => [
                $ledgerEntry->id => self::sourceLedgerOptionLabel($ledgerEntry),
            ])
            ->all();
    }

    public static function sourceLedgerOptionLabel(LedgerEntry $ledgerEntry): string
    {
        return collect([
            "#{$ledgerEntry->id}",
            $ledgerEntry->entry_type,
            $ledgerEntry->description,
            'Amount: PHP '.number_format((float) $ledgerEntry->amount, 2),
            'Balance: PHP '.number_format((float) $ledgerEntry->running_balance, 2),
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'enrollment_id',
        'source_ledger_entry_id',
        'ledger_entry_id',
        'adjustment_type',
        'amount',
        'reason',
        'evidence_reference',
        'posted_at',
        'posted_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function sourceLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'source_ledger_entry_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
