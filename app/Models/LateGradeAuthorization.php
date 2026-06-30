<?php

namespace App\Models;

use Database\Factories\LateGradeAuthorizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $opens_at
 * @property Carbon $closes_at
 */
class LateGradeAuthorization extends Model
{
    /** @use HasFactory<LateGradeAuthorizationFactory> */
    use HasFactory;

    public const StateActive = 'ACTIVE';

    public const StateExpired = 'EXPIRED';

    public const StateRevoked = 'REVOKED';

    public const PeriodPrelim = 'prelim';

    public const PeriodMidterm = 'midterm';

    public const PeriodFinal = 'final';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'grade_roster_id',
        'term_offering_id',
        'faculty_user_id',
        'grading_period',
        'reason',
        'approved_by',
        'opens_at',
        'closes_at',
        'state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GradeRoster, $this> */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(GradeRoster::class, 'grade_roster_id');
    }

    /** @return BelongsTo<TermOffering, $this> */
    public function termOffering(): BelongsTo
    {
        return $this->belongsTo(TermOffering::class);
    }

    /** @return BelongsTo<User, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }
}
