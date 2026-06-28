<?php

namespace App\Models;

use Database\Factories\CurriculumVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumVersion extends Model
{
    /** @use HasFactory<CurriculumVersionFactory> */
    use HasFactory;

    public const StateDraft = 'DRAFT';

    public const StateRecordedApproved = 'RECORDED_APPROVED';

    public const StateActive = 'ACTIVE';

    public const StateSuperseded = 'SUPERSEDED';

    public const StateArchived = 'ARCHIVED';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'program_id',
        'version_code',
        'name',
        'effective_entry_term_id',
        'state',
        'approval_reference',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function effectiveEntryTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'effective_entry_term_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CurriculumEntry::class);
    }
}
