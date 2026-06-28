<?php

namespace App\Models;

use Database\Factories\FacultyQualificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyQualification extends Model
{
    /** @use HasFactory<FacultyQualificationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'faculty_user_id',
        'course_id',
        'is_active',
        'recorded_by',
        'recorded_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
