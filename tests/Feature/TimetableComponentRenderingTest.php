<?php

namespace Tests\Feature;

use Tests\TestCase;

class TimetableComponentRenderingTest extends TestCase
{
    public function test_native_week_fallback_marks_unplottable_meetings_without_marking_normal_rows(): void
    {
        $presentation = [
            'title' => 'Term timetable',
            'source' => 'Recorded meetings',
            'meetings' => [
                [
                    'id' => 'missing-time',
                    'plottable' => false,
                    'dayLabel' => 'Day unavailable',
                    'time' => 'Time unavailable',
                    'courseCode' => 'IT101',
                    'courseTitle' => 'Introduction to Computing',
                    'room' => 'Room unavailable',
                    'class' => 'IT-1A',
                    'faculty' => 'Faculty unavailable',
                    'modality' => 'In person',
                    'component' => 'Lecture',
                    'duration' => 'Not available',
                    'offGrid' => false,
                    'day' => null,
                    'start' => null,
                    'end' => null,
                    'lane' => 0,
                    'lanes' => 1,
                    'top' => 0,
                    'height' => 0,
                    'status' => 'Unplaced',
                ],
                [
                    'id' => 'scheduled',
                    'plottable' => true,
                    'dayLabel' => 'Monday',
                    'time' => '8:00 AM–9:00 AM',
                    'courseCode' => 'IT102',
                    'courseTitle' => 'Computer Systems',
                    'room' => 'Room 1',
                    'class' => 'IT-1A',
                    'faculty' => 'Faculty unavailable',
                    'modality' => 'In person',
                    'component' => 'Lecture',
                    'duration' => '1h',
                    'offGrid' => false,
                    'day' => 1,
                    'start' => 480,
                    'end' => 540,
                    'lane' => 0,
                    'lanes' => 1,
                    'top' => 0,
                    'height' => 100,
                    'status' => 'Scheduled',
                ],
            ],
            'slots' => 0,
            'days' => [],
            'ticks' => [],
            'blockedPeriods' => [],
            'courseColors' => [],
            'snapshotAvailable' => true,
            'filters' => ['faculty' => [], 'room' => [], 'class' => [], 'modality' => []],
        ];

        $rendered = $this->blade(
            '<x-tala-timetable :presentation="$presentation" :embedded="true" :controlled="true" :native-details="true" />',
            ['presentation' => $presentation],
        );

        $rendered->assertSee('class="tala-timetable-list-meeting tala-timetable-unplaced" x-show="matches(meetings.find(meeting => meeting.id === \'missing-time\'))"', false);
        $rendered->assertSee('class="tala-timetable-list-meeting " x-show="matches(meetings.find(meeting => meeting.id === \'scheduled\'))"', false);
    }
}
