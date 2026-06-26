<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    use HasFactory;
    protected $fillable = [
        'owner_type',
        'owner_id',
        'requirement_type',
        'status',
        'blocking_level',
        'evidence_method',
        'verification_status',
        'deadline',
        'source_policy',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
