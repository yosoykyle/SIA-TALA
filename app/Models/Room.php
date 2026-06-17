<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'building',
        'capacity',
        'is_active',
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
            $this->building,
            $this->capacity === null ? null : "{$this->capacity} seats",
        ])->filter()->implode(' | ');
    }
}
