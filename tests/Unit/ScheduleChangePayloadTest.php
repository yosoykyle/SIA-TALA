<?php

namespace Tests\Unit;

use App\Models\SectionMeeting;
use App\Support\Scheduling\ScheduleChangePayload;
use PHPUnit\Framework\TestCase;

class ScheduleChangePayloadTest extends TestCase
{
    public function test_it_builds_old_payload_snapshot_from_section_meeting(): void
    {
        $sectionMeeting = new SectionMeeting([
            'section_delivery_group_id' => 5,
            'faculty_id' => 7,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:00:00',
            'ends_at' => '10:30:00',
            'modality' => 'on_site',
        ]);

        $this->assertSame([
            'section_delivery_group_id' => 5,
            'faculty_id' => 7,
            'room' => 'RUT 201',
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '10:30',
            'modality' => 'on_site',
        ], ScheduleChangePayload::fromSectionMeeting($sectionMeeting));
    }

    public function test_it_builds_new_payload_from_typed_form_fields(): void
    {
        $this->assertSame([
            'section_delivery_group_id' => 11,
            'faculty_id' => 9,
            'room' => 'LAB 2',
            'day_of_week' => 5,
            'starts_at' => '13:00',
            'ends_at' => '15:00',
            'modality' => 'blended',
        ], ScheduleChangePayload::fromFormData([
            'new_section_delivery_group_id' => '11',
            'new_faculty_id' => '9',
            'new_room' => 'LAB 2',
            'new_day_of_week' => '5',
            'new_starts_at' => '13:00:00',
            'new_ends_at' => '15:00:00',
            'new_modality' => 'blended',
        ]));
    }

    public function test_it_strips_form_only_fields_before_model_persistence(): void
    {
        $this->assertSame([
            'term_id' => 1,
            'section_delivery_group_id' => 99,
            'reason' => 'Room conflict.',
        ], ScheduleChangePayload::stripFormOnlyFields([
            'term_id' => 1,
            'section_delivery_group_id' => 99,
            'new_section_delivery_group_id' => 12,
            'new_day_of_week' => 3,
            'new_starts_at' => '09:00',
            'reason' => 'Room conflict.',
        ]));
    }
}
