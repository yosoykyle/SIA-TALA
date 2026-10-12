<?php

namespace App\Filament\Resources\CalendarEvents\Schemas;

use App\Filament\Resources\CalendarEvents\Pages\CreateCalendarEvent;
use App\Filament\Resources\CalendarEvents\Pages\EditCalendarEvent;
use App\Models\CalendarEvent;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CalendarEventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Weekly unavailable time')
                ->description('Prevent meetings during these times for this Term. Record dated no-class exceptions in Calendar.')
                ->schema([
                    Select::make('term_id')
                        ->relationship('term', 'label')
                        ->default(fn (CreateCalendarEvent|EditCalendarEvent $livewire): ?int => $livewire->termId)
                        ->hidden(fn (CreateCalendarEvent|EditCalendarEvent $livewire): bool => $livewire->termId !== null)
                        ->dehydrated()
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('event_type')
                        ->options(CalendarEvent::recurringBlockTypeOptions())
                        ->default(CalendarEvent::TypeUnavailable)
                        ->required()
                        ->visible(fn (): bool => self::isRegistrar()),
                    Select::make('scope_type')
                        ->options(CalendarEvent::recurringBlockScopeOptions())
                        ->default(fn (CreateCalendarEvent|EditCalendarEvent $livewire): string => $livewire->resourceView === 'rooms' ? CalendarEvent::ScopeRoom : CalendarEvent::ScopeFaculty)
                        ->required()
                        ->live()
                        ->visible(fn (): bool => self::isRegistrar()),
                    Select::make('faculty_user_id')
                        ->label('Faculty')
                        ->relationship('faculty', 'name', modifyQueryUsing: fn ($query) => $query->role(User::StaffRoleFaculty)->orderBy('name'))
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('scope_type') === CalendarEvent::ScopeFaculty)
                        ->visible(fn (Get $get): bool => ! self::isFaculty() && $get('scope_type') === CalendarEvent::ScopeFaculty),
                    Select::make('room_id')
                        ->label('Room')
                        ->relationship('room', 'code', modifyQueryUsing: fn ($query) => $query->where('is_active', true)->orderBy('code'))
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => $get('scope_type') === CalendarEvent::ScopeRoom)
                        ->visible(fn (Get $get): bool => self::isRegistrar() && $get('scope_type') === CalendarEvent::ScopeRoom),
                    Select::make('day_of_week')
                        ->label('Day')
                        ->options(CalendarEvent::dayOptions())
                        ->required(),
                    TimePicker::make('starts_at')
                        ->label('From')
                        ->timezone((string) config('app.timezone'))
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('ends_at')
                        ->label('To')
                        ->timezone((string) config('app.timezone'))
                        ->seconds(false)
                        ->after('starts_at')
                        ->required(),
                    Select::make('state')
                        ->options(CalendarEvent::stateOptions())
                        ->default(CalendarEvent::StateActive)
                        ->required()
                        ->visible(fn (): bool => ! self::isFaculty()),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    private static function isFaculty(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    private static function isRegistrar(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }
}
