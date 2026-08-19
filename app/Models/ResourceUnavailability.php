<?php

namespace App\Models;

use Database\Factories\ResourceUnavailabilityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceUnavailability extends Model
{
    /** @use HasFactory<ResourceUnavailabilityFactory> */
    use HasFactory;

    protected $fillable = [
        'term_id', 'room_id', 'faculty_user_id', 'effective_on', 'day_of_week',
        'starts_at', 'ends_at', 'authority_reference', 'reason',
    ];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'day_of_week' => 'integer'];
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<User, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }
}
