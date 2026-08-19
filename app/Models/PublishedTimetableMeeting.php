<?php

namespace App\Models;

use Database\Factories\PublishedTimetableMeetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishedTimetableMeeting extends Model
{
    /** @use HasFactory<PublishedTimetableMeetingFactory> */
    use HasFactory;

    protected $fillable = [
        'published_timetable_version_id', 'section_id', 'scheduling_demand_id',
        'faculty_user_id', 'room_id', 'meeting_sequence', 'day_of_week',
        'starts_at', 'ends_at', 'modality', 'location_label', 'supersedes_meeting_id',
    ];

    protected function casts(): array
    {
        return ['meeting_sequence' => 'integer', 'day_of_week' => 'integer'];
    }

    /** @return BelongsTo<PublishedTimetableVersion, $this> */
    public function timetableVersion(): BelongsTo
    {
        return $this->belongsTo(PublishedTimetableVersion::class, 'published_timetable_version_id');
    }

    /** @return BelongsTo<Section, $this> */
    public function classOffering(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    /** @return BelongsTo<User, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(User::class, 'faculty_user_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<SchedulingDemand, $this> */
    public function schedulingDemand(): BelongsTo
    {
        return $this->belongsTo(SchedulingDemand::class);
    }
}
