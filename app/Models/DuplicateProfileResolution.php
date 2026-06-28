<?php

namespace App\Models;

use Database\Factories\DuplicateProfileResolutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DuplicateProfileResolution extends Model
{
    /** @use HasFactory<DuplicateProfileResolutionFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'duplicate_student_profile_id',
        'primary_student_profile_id',
        'resolution_type',
        'reason',
        'resolved_by',
        'resolved_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function duplicateStudent(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'duplicate_student_profile_id');
    }

    public function primaryStudent(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'primary_student_profile_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
