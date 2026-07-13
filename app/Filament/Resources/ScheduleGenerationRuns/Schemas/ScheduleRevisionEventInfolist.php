<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\Schemas;

use App\Models\Course;
use App\Models\Room;
use App\Models\ScheduleRevisionEvent;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section as InfolistSection;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class ScheduleRevisionEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                InfolistSection::make('Revision')
                    ->schema([
                        TextEntry::make('change_type')
                            ->label('Change Type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ScheduleRevisionEvent::changeTypeOptions()[$state] ?? Str::headline($state)),
                        TextEntry::make('section_label')
                            ->label('Section')
                            ->state(fn (ScheduleRevisionEvent $record): string => self::sectionLabel($record)),
                        TextEntry::make('course_label')
                            ->label('Course')
                            ->state(fn (ScheduleRevisionEvent $record): string => self::courseLabel($record)),
                        TextEntry::make('meeting_label')
                            ->label('Meeting')
                            ->state(fn (ScheduleRevisionEvent $record): string => self::meetingLabel($record)),
                        TextEntry::make('effective_date')
                            ->label('Effective Date')
                            ->date(),
                        TextEntry::make('actor_label')
                            ->label('Changed By')
                            ->state(fn (ScheduleRevisionEvent $record): string => self::actorLabel($record)),
                        TextEntry::make('created_at')
                            ->label('Recorded At')
                            ->dateTime(),
                        TextEntry::make('reason')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                InfolistSection::make('Impact')
                    ->schema([
                        TextEntry::make('affected_student_count')
                            ->label('Affected Students')
                            ->numeric(),
                        TextEntry::make('affected_faculty_count')
                            ->label('Affected Faculty')
                            ->numeric(),
                    ])
                    ->columns(2),
                InfolistSection::make('Before')
                    ->schema(self::snapshotEntries('old_snapshot_json', 'before'))
                    ->columns(2),
                InfolistSection::make('After')
                    ->schema(self::snapshotEntries('new_snapshot_json', 'after'))
                    ->columns(2),
            ]);
    }

    /** @return list<TextEntry> */
    private static function snapshotEntries(string $attribute, string $prefix): array
    {
        return [
            TextEntry::make($prefix.'_faculty')
                ->label('Faculty')
                ->state(fn (ScheduleRevisionEvent $record): string => self::facultyLabel(self::snapshot($record, $attribute))),
            TextEntry::make($prefix.'_room')
                ->label('Room')
                ->state(fn (ScheduleRevisionEvent $record): string => self::roomLabel(self::snapshot($record, $attribute))),
            TextEntry::make($prefix.'_day')
                ->label('Day')
                ->state(fn (ScheduleRevisionEvent $record): string => self::dayLabel(self::snapshot($record, $attribute))),
            TextEntry::make($prefix.'_time')
                ->label('Time')
                ->state(fn (ScheduleRevisionEvent $record): string => self::timeLabel(self::snapshot($record, $attribute))),
            TextEntry::make($prefix.'_modality')
                ->label('Modality')
                ->badge()
                ->state(fn (ScheduleRevisionEvent $record): string => self::modalityLabel(self::snapshot($record, $attribute))),
            TextEntry::make($prefix.'_state')
                ->label('Meeting State')
                ->badge()
                ->state(fn (ScheduleRevisionEvent $record): string => Str::headline((string) (self::snapshot($record, $attribute)['state'] ?? '-'))),
        ];
    }

    /** @return array<string, mixed> */
    private static function snapshot(ScheduleRevisionEvent $record, string $attribute): array
    {
        $snapshot = $record->getAttribute($attribute);

        return is_array($snapshot) ? $snapshot : [];
    }

    private static function sectionLabel(ScheduleRevisionEvent $record): string
    {
        $section = self::meeting($record)?->schedulingDemand?->sectionDeliveryGroup?->section;

        return $section instanceof Section ? $section->code : 'Section unavailable';
    }

    private static function courseLabel(ScheduleRevisionEvent $record): string
    {
        $course = self::meeting($record)?->schedulingDemand?->termOffering?->course();

        return $course instanceof Course ? $course->code : 'Course unavailable';
    }

    private static function meetingLabel(ScheduleRevisionEvent $record): string
    {
        $meeting = self::meeting($record);
        $sequence = $meeting instanceof SectionMeeting
            ? (int) $meeting->meeting_sequence
            : (int) (self::snapshot($record, 'new_snapshot_json')['meeting_sequence'] ?? 0);

        return $sequence > 0 ? 'Meeting '.$sequence : 'Meeting unavailable';
    }

    private static function actorLabel(ScheduleRevisionEvent $record): string
    {
        $actor = $record->getRelationValue('changedBy');

        return $actor instanceof User ? $actor->name : 'User #'.(int) $record->changed_by;
    }

    private static function meeting(ScheduleRevisionEvent $record): ?SectionMeeting
    {
        $meeting = $record->getRelationValue('sectionMeeting');

        return $meeting instanceof SectionMeeting ? $meeting : null;
    }

    /** @param array<string, mixed> $snapshot */
    private static function facultyLabel(array $snapshot): string
    {
        $id = is_numeric($snapshot['faculty_user_id'] ?? null) ? (int) $snapshot['faculty_user_id'] : null;
        $faculty = $id !== null ? User::query()->find($id) : null;

        return $faculty instanceof User ? $faculty->name : ($id === null ? 'No faculty' : 'User #'.$id);
    }

    /** @param array<string, mixed> $snapshot */
    private static function roomLabel(array $snapshot): string
    {
        $id = is_numeric($snapshot['room_id'] ?? null) ? (int) $snapshot['room_id'] : null;
        $room = $id !== null ? Room::query()->find($id) : null;

        return $room instanceof Room ? $room->displayLabel() : ($id === null ? 'No physical room' : 'Room #'.$id);
    }

    /** @param array<string, mixed> $snapshot */
    private static function dayLabel(array $snapshot): string
    {
        $day = is_numeric($snapshot['day_of_week'] ?? null) ? (int) $snapshot['day_of_week'] : null;

        return $day === null ? '-' : (SectionMeeting::dayOptions()[$day] ?? 'Day '.$day);
    }

    /** @param array<string, mixed> $snapshot */
    private static function timeLabel(array $snapshot): string
    {
        $start = filled($snapshot['starts_at'] ?? null) ? mb_substr((string) $snapshot['starts_at'], 0, 5) : '-';
        $end = filled($snapshot['ends_at'] ?? null) ? mb_substr((string) $snapshot['ends_at'], 0, 5) : '-';

        return $start.' - '.$end;
    }

    /** @param array<string, mixed> $snapshot */
    private static function modalityLabel(array $snapshot): string
    {
        $modality = (string) ($snapshot['modality'] ?? '');

        return TermOffering::modalityOptions()[$modality] ?? ($modality === '' ? '-' : Str::headline($modality));
    }
}
