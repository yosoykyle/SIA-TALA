<?php

namespace App\Models;

use Database\Factories\RoomFeatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomFeature extends Model
{
    /** @use HasFactory<RoomFeatureFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'room_id',
        'feature_key',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
