<?php

namespace App\Models;

use Database\Factories\StudentProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentProfile extends Model
{
    /** @use HasFactory<StudentProfileFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'student_id',
        'lrn',
        'program_id',
        'year_level',
        'operational_status',
        'status_reason',
        'modality',
        'current_balance',
        'hard_copy_received',
        'last_status_changed_at',
        'graduated_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'hard_copy_received' => 'boolean',
            'last_status_changed_at' => 'datetime',
            'graduated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
