<?php

namespace App\Filament\Resources\ScheduleChanges\Schemas;

use App\Models\ScheduleChange;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\Scheduling\ScheduleChangePayload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScheduleChangeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Requested Schedule Change')
                    ->description('Registrar records the requested schedule change using typed fields. The system stores old/new values as internal audit snapshots.')
                    ->schema([
                        Select::make('term_id')
                            ->label('Term')
                            ->relationship('term', 'term_name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('section_meeting_id')
                            ->label('Official schedule')
                            ->relationship('sectionMeeting', 'id')
                            ->getOptionLabelFromRecordUsing(fn (SectionMeeting $record): string => self::sectionMeetingLabel($record))
                            ->searchable()
                            ->preload()
                            ->required(),
                        Hidden::make('status')
                            ->default('proposed')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                        Select::make('new_faculty_id')
                            ->label('Requested faculty')
                            ->options(fn (): array => User::role('faculty')->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->default(fn (?ScheduleChange $record): ?int => self::payloadValue($record, 'faculty_id'))
                            ->dehydrated(false),
                        TextInput::make('new_room')
                            ->label('Requested room')
                            ->maxLength(255)
                            ->default(fn (?ScheduleChange $record): ?string => self::payloadValue($record, 'room'))
                            ->dehydrated(false),
                        Select::make('new_day_of_week')
                            ->label('Requested day')
                            ->options(self::dayOptions())
                            ->required()
                            ->default(fn (?ScheduleChange $record): ?int => self::payloadValue($record, 'day_of_week'))
                            ->dehydrated(false),
                        TimePicker::make('new_starts_at')
                            ->label('Requested start time')
                            ->seconds(false)
                            ->required()
                            ->default(fn (?ScheduleChange $record): ?string => self::payloadValue($record, 'starts_at'))
                            ->dehydrated(false),
                        TimePicker::make('new_ends_at')
                            ->label('Requested end time')
                            ->seconds(false)
                            ->after('new_starts_at')
                            ->required()
                            ->default(fn (?ScheduleChange $record): ?string => self::payloadValue($record, 'ends_at'))
                            ->dehydrated(false),
                        Select::make('new_modality')
                            ->label('Requested modality')
                            ->options([
                                'on_site' => 'On-site',
                                'online' => 'Online',
                                'modular' => 'Modular',
                                'blended' => 'Blended',
                            ])
                            ->required()
                            ->default(fn (?ScheduleChange $record): ?string => self::payloadValue($record, 'modality'))
                            ->dehydrated(false),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(1000)
                            ->columnSpanFull(),
                        Hidden::make('requested_by')
                            ->default(fn (): ?int => auth()->id())
                            ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function dayOptions(): array
    {
        return [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];
    }

    private static function sectionMeetingLabel(SectionMeeting $record): string
    {
        $record->loadMissing(['section', 'subject', 'faculty']);

        $day = self::dayOptions()[$record->day_of_week] ?? 'Unscheduled';
        $time = trim(implode('-', array_filter([$record->starts_at, $record->ends_at])));

        return collect([
            $record->section?->name,
            $record->subject?->code,
            $record->faculty?->name,
            $day,
            $time !== '' ? $time : null,
            $record->room,
        ])->filter()->implode(' | ');
    }

    private static function payloadValue(?ScheduleChange $record, string $key): mixed
    {
        if (! $record instanceof ScheduleChange || ! is_array($record->new_payload)) {
            return null;
        }

        return ScheduleChangePayload::normalize($record->new_payload)[$key] ?? null;
    }
}
