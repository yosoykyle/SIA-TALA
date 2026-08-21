<?php

namespace App\Models;

use Database\Factories\RegistrationCaseEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $recorded_at
 */
class RegistrationCaseEvent extends Model
{
    /** @use HasFactory<RegistrationCaseEventFactory> */
    use HasFactory;

    protected $fillable = [
        'enrollment_id', 'sequence', 'event_type', 'from_outcome', 'to_outcome',
        'reason', 'authority_reference', 'actor_id', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'recorded_at' => 'datetime'];
    }

    /** @return BelongsTo<Enrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
