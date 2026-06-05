<?php

namespace App\Models;

use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'student_profile_id',
        'term_id',
        'enrollment_id',
        'entry_type',
        'reference_type',
        'reference_id',
        'description',
        'amount',
        'running_balance',
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
            'running_balance' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function displayLabel(): string
    {
        return collect([
            "#{$this->id}",
            $this->entry_type,
            $this->description,
            'Amount: '.number_format((float) $this->amount, 2),
            'Balance: '.number_format((float) $this->running_balance, 2),
        ])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode(' - ');
    }
}
