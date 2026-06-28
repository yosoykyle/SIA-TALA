<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    public const TypeLectureRoom = 'LECTURE_ROOM';

    public const TypeLaboratory = 'LABORATORY';

    public const TypeComputerLaboratory = 'COMPUTER_LABORATORY';

    public const TypeSpecialRoom = 'SPECIAL_ROOM';

    public const TypeOnlineNoPhysicalRoom = 'ONLINE_NO_PHYSICAL_ROOM';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'building',
        'room_type',
        'capacity',
        'is_active',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function selectOptions(?string $currentRoom = null): array
    {
        $rooms = self::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Room $room): array => [
                $room->code => $room->displayLabel(),
            ])
            ->all();

        if (filled($currentRoom) && ! array_key_exists($currentRoom, $rooms)) {
            $rooms[$currentRoom] = "{$currentRoom} (inactive or legacy)";
        }

        return $rooms;
    }

    public function displayLabel(): string
    {
        return collect([
            $this->code,
            $this->name,
            $this->room_type,
            $this->building,
            "{$this->capacity} seats",
        ])->filter()->implode(' | ');
    }

    public function features(): HasMany
    {
        return $this->hasMany(RoomFeature::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }
}
