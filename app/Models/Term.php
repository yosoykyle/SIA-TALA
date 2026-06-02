<?php

namespace App\Models;

use Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Term extends Model
{
    /** @use HasFactory<TermFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'academic_year_id',
        'term_name',
        'term_type',
        'is_active',
        'term_start_date',
        'term_end_date',
        'class_start_date',
        'class_end_date',
        'scheduling_starts_at',
        'enrollment_starts_at',
        'enrollment_ends_at',
        'late_enrollment_ends_at',
        'payment_deadline',
        'adjustment_ends_at',
        'locked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'term_start_date' => 'date',
            'term_end_date' => 'date',
            'class_start_date' => 'date',
            'class_end_date' => 'date',
            'scheduling_starts_at' => 'datetime',
            'enrollment_starts_at' => 'datetime',
            'enrollment_ends_at' => 'datetime',
            'late_enrollment_ends_at' => 'datetime',
            'payment_deadline' => 'datetime',
            'adjustment_ends_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
