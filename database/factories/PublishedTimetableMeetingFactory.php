<?php

namespace Database\Factories;

use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\Room;
use App\Models\Section;
use App\Models\TermCalendarPackage;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublishedTimetableMeeting>
 */
class PublishedTimetableMeetingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'published_timetable_version_id' => PublishedTimetableVersion::factory(),
            'section_id' => function (array $attributes): Factory {
                $version = PublishedTimetableVersion::query()->findOrFail($attributes['published_timetable_version_id']);

                return Section::factory()->state([
                    'term_calendar_package_id' => TermCalendarPackage::factory()->state(['term_id' => $version->term_id]),
                    'term_offering_id' => TermOffering::factory()->state(['term_id' => $version->term_id]),
                ]);
            },
            'scheduling_demand_id' => null,
            'faculty_user_id' => User::factory(),
            'room_id' => Room::factory(),
            'meeting_sequence' => 1,
            'day_of_week' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '09:30:00',
            'modality' => TermOffering::ModalityFaceToFace,
            'location_label' => 'Synthetic Room',
            'supersedes_meeting_id' => null,
        ];
    }
}
