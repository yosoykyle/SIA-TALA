<?php

namespace App\Models;

use Database\Factories\ProgramAuthorityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramAuthority extends Model
{
    /** @use HasFactory<ProgramAuthorityFactory> */
    use HasFactory;

    public const StateDraft = 'Draft';

    public const StateActive = 'Active';

    public const StateSuperseded = 'Superseded';

    protected $fillable = [
        'program_id', 'authority_type', 'authority_reference', 'regulator',
        'effective_from', 'effective_until', 'curriculum_source_reference',
        'state', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Program, $this> */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
