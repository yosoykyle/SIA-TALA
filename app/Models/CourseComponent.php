<?php

namespace App\Models;

use Database\Factories\CourseComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseComponent extends Model
{
    /** @use HasFactory<CourseComponentFactory> */
    use HasFactory;

    public const TypeLecture = 'LECTURE';

    public const TypeLaboratory = 'LABORATORY';

    public const RoomTypeLectureRoom = 'LECTURE_ROOM';

    public const RoomTypeComputerLaboratory = 'COMPUTER_LABORATORY';

    public const RoomTypeLaboratory = 'LABORATORY';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'course_specification_id',
        'component_type',
        'weekly_contact_hours',
        'meeting_pattern',
        'room_type_default',
        'required_room_feature_keys',
        'modality_restriction',
        'requires_consecutive_block',
        'same_faculty',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekly_contact_hours' => 'decimal:2',
            'required_room_feature_keys' => 'array',
            'requires_consecutive_block' => 'boolean',
            'same_faculty' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<CourseSpecification, $this> */
    public function courseSpecification(): BelongsTo
    {
        return $this->belongsTo(CourseSpecification::class);
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TypeLecture => 'Lecture',
            self::TypeLaboratory => 'Laboratory',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roomTypeOptions(): array
    {
        return [
            self::RoomTypeLectureRoom => 'Lecture Room',
            self::RoomTypeComputerLaboratory => 'Computer Laboratory',
            self::RoomTypeLaboratory => 'Laboratory',
        ];
    }

    /** @return array<string, string> */
    public static function meetingPatternOptions(): array
    {
        return [
            '1x60' => '1 × 60 minutes',
            '1x90' => '1 × 90 minutes',
            '1x120' => '1 × 120 minutes',
            '1x180' => '1 × 180 minutes',
            '2x60' => '2 × 60 minutes',
            '2x90' => '2 × 90 minutes',
            '2x120' => '2 × 120 minutes',
            '3x60' => '3 × 60 minutes',
            '4x60' => '4 × 60 minutes',
        ];
    }

    /** @return array{count:int,duration_minutes:int}|null */
    public static function parseMeetingPattern(?string $pattern): ?array
    {
        if (! is_string($pattern) || preg_match('/^([1-5])x(30|60|90|120|150|180|210|240)$/', $pattern, $matches) !== 1) {
            return null;
        }

        return [
            'count' => (int) $matches[1],
            'duration_minutes' => (int) $matches[2],
        ];
    }
}
