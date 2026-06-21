<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PromissoryNote extends Model
{
    public const StatusPending = 'pending';

    public const StatusApproved = 'approved';

    public const StatusExpired = 'expired';

    public const StatusSettled = 'settled';

    public const StatusRejected = 'rejected';

    public const StatusCancelled = 'cancelled';

    public const SourceStudent = 'student';

    public const SourceStaffAssisted = 'staff_assisted';

    protected $attributes = [
        'status' => self::StatusPending,
        'request_source' => self::SourceStaffAssisted,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'enrollment_id',
        'ledger_entry_id',
        'amount',
        'due_date',
        'status',
        'reason',
        'requested_by',
        'requested_at',
        'request_source',
        'approved_by',
        'approved_at',
        'expired_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'settled_by',
        'settled_at',
        'expiry_warning_sent_at',
        'expiry_notified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'expired_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'settled_at' => 'datetime',
            'expiry_warning_sent_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
        ];
    }

    public static function studentOptionLabel(StudentProfile $studentProfile): string
    {
        $studentProfile->loadMissing('user');

        return collect([
            $studentProfile->student_id,
            $studentProfile->user?->name,
            $studentProfile->year_level,
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }

    /**
     * @return array<int, string>
     */
    public static function enrollmentOptionsFor(int|string|null $studentProfileId, int|string|null $termId = null): array
    {
        if (blank($studentProfileId)) {
            return [];
        }

        return Enrollment::query()
            ->with('term')
            ->where('student_profile_id', $studentProfileId)
            ->when(filled($termId), fn ($query) => $query->where('term_id', $termId))
            ->latest('id')
            ->get()
            ->mapWithKeys(fn (Enrollment $enrollment): array => [
                $enrollment->id => self::enrollmentOptionLabel($enrollment),
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function ledgerEntryOptionsFor(
        int|string|null $studentProfileId,
        int|string|null $termId = null,
        int|string|null $enrollmentId = null,
    ): array {
        if (blank($studentProfileId)) {
            return [];
        }

        return LedgerEntry::query()
            ->where('student_profile_id', $studentProfileId)
            ->when(filled($termId), fn ($query) => $query->where('term_id', $termId))
            ->when(filled($enrollmentId), fn ($query) => $query->where('enrollment_id', $enrollmentId))
            ->latest('id')
            ->get()
            ->mapWithKeys(fn (LedgerEntry $ledgerEntry): array => [
                $ledgerEntry->id => self::ledgerEntryOptionLabel($ledgerEntry),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validateAccountingScopeData(array $data): array
    {
        $studentProfileId = self::integerFormId($data, 'student_profile_id');
        $termId = self::integerFormId($data, 'term_id');
        $enrollmentId = self::integerFormId($data, 'enrollment_id');
        $ledgerEntryId = self::integerFormId($data, 'ledger_entry_id');
        $errors = [];

        if ($studentProfileId === null || ! StudentProfile::query()->whereKey($studentProfileId)->exists()) {
            $errors['student_profile_id'] = 'Select a valid student before recording a promissory note.';
        }

        if (filled($data['term_id'] ?? null) && $termId === null) {
            $errors['term_id'] = 'Select a valid term for the promissory note.';
        }

        if ($termId !== null && ! Term::query()->whereKey($termId)->exists()) {
            $errors['term_id'] = 'Select a valid term for the promissory note.';
        }

        if (filled($data['enrollment_id'] ?? null) && $enrollmentId === null) {
            $errors['enrollment_id'] = 'Select a valid enrollment for the selected student.';
        }

        $enrollment = $enrollmentId === null ? null : Enrollment::query()->find($enrollmentId);

        if ($enrollmentId !== null && $enrollment === null) {
            $errors['enrollment_id'] = 'Select a valid enrollment for the selected student.';
        }

        if ($enrollment !== null && $studentProfileId !== null && $enrollment->student_profile_id !== $studentProfileId) {
            $errors['enrollment_id'] = 'The selected enrollment must belong to the selected student.';
        }

        if ($enrollment !== null && $termId !== null && $enrollment->term_id !== $termId) {
            $errors['enrollment_id'] = 'The selected enrollment must belong to the selected term.';
        }

        if (filled($data['ledger_entry_id'] ?? null) && $ledgerEntryId === null) {
            $errors['ledger_entry_id'] = 'Select a valid ledger entry for the selected student.';
        }

        $ledgerEntry = $ledgerEntryId === null ? null : LedgerEntry::query()->find($ledgerEntryId);

        if ($ledgerEntryId !== null && $ledgerEntry === null) {
            $errors['ledger_entry_id'] = 'Select a valid ledger entry for the selected student.';
        }

        if ($ledgerEntry !== null && $studentProfileId !== null && $ledgerEntry->student_profile_id !== $studentProfileId) {
            $errors['ledger_entry_id'] = 'The selected ledger entry must belong to the selected student.';
        }

        if ($ledgerEntry !== null && $termId !== null && (int) $ledgerEntry->term_id !== $termId) {
            $errors['ledger_entry_id'] = 'The selected ledger entry must belong to the selected term.';
        }

        if ($ledgerEntry !== null && $enrollmentId !== null && (int) $ledgerEntry->enrollment_id !== $enrollmentId) {
            $errors['ledger_entry_id'] = 'The selected ledger entry must belong to the selected enrollment.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $data['student_profile_id'] = $studentProfileId;
        $data['term_id'] = $termId;
        $data['enrollment_id'] = $enrollmentId;
        $data['ledger_entry_id'] = $ledgerEntryId;

        return $data;
    }

    public static function enrollmentOptionLabel(Enrollment $enrollment): string
    {
        return $enrollment->displayLabel();
    }

    public static function ledgerEntryOptionLabel(LedgerEntry $ledgerEntry): string
    {
        return $ledgerEntry->displayLabel();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function integerFormId(array $data, string $field): ?int
    {
        $value = $data[$field] ?? null;

        if (blank($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
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

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
