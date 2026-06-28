<?php

namespace App\Models;

use Database\Factories\FacultyTermLoadOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacultyTermLoadOverride extends Model
{
    /** @use HasFactory<FacultyTermLoadOverrideFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'faculty_user_id',
        'term_id',
        'default_max_units_snapshot',
        'approved_overload_units',
        'authority',
        'reason',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_max_units_snapshot' => 'decimal:2',
            'approved_overload_units' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function allowedLoadUnits(): float
    {
        return (float) $this->default_max_units_snapshot + (float) $this->approved_overload_units;
    }

    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }
}
