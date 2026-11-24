<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\Rooms\Pages\ViewRoom;
use App\Filament\Resources\Rooms\RoomResource;
use App\Models\Room;
use App\Models\User;
use App\Support\DisplayDateTime;
use App\Support\RoomEquipment;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Component as LivewireComponent;

class RoomInfolist
{
    public static function configure(Schema $schema, bool $compact = false): Schema
    {
        return $schema->components([
            Section::make('Room summary')
                ->compact()
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('code')
                                ->label('Room code')
                                ->weight('semibold')
                                ->copyable(),
                            TextEntry::make('is_active')
                                ->label('Status')
                                ->badge()
                                ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                                ->icon(fn (bool $state): Heroicon => $state ? Heroicon::CheckCircle : Heroicon::MinusCircle)
                                ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                            TextEntry::make('room_type')
                                ->label('Room type')
                                ->badge()
                                ->color('gray')
                                ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Room::typeOptions()[$state] ?? str($state)->headline()->toString())),
                        ])
                        ->columnSpanFull(),
                    Grid::make(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('capacity')
                                ->numeric()
                                ->suffix(' seats')
                                ->placeholder('-')
                                ->extraAttributes(['class' => 'tabular-nums']),
                            TextEntry::make('building')
                                ->placeholder('-'),
                            TextEntry::make('name')
                                ->label('Room name')
                                ->placeholder('-'),
                        ])
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            Grid::make(['default' => 1, 'md' => 2])
                ->schema([
                    Section::make('Equipment')
                        ->compact()
                        ->schema([
                            TextEntry::make('features.feature_key')
                                ->hiddenLabel()
                                ->badge()
                                ->color('gray')
                                ->formatStateUsing(fn (?string $state): string => RoomEquipment::label($state))
                                ->placeholder('No special equipment recorded'),
                        ]),
                    Section::make('Notes')
                        ->compact()
                        ->schema([
                            TextEntry::make('notes')
                                ->hiddenLabel()
                                ->placeholder('No notes recorded')
                                ->extraAttributes(['class' => 'leading-relaxed']),
                        ]),
                ])
                ->columnSpanFull(),
            TextEntry::make('record_metadata')
                ->hiddenLabel()
                ->state(fn (Room $record): string => 'Created '.DisplayDateTime::format($record->created_at).' · Last updated '.DisplayDateTime::format($record->updated_at).' (Asia/Manila)')
                ->color('gray')
                ->extraAttributes(['class' => 'text-xs text-zinc-500 dark:text-zinc-400 tabular-nums'])
                ->columnSpanFull(),
            Actions::make([
                Action::make('backToRooms')
                    ->visible(fn (LivewireComponent $livewire): bool => ! ($livewire instanceof ViewRoom && $livewire->termId !== null))
                    ->label(fn (LivewireComponent $livewire): string => $livewire instanceof ViewRoom && $livewire->returnToResources ? 'Back to Faculty & rooms' : 'Back to rooms')
                    ->icon('heroicon-o-arrow-left')
                    ->color('gray')
                    ->url(fn (LivewireComponent $livewire): string => $livewire instanceof ViewRoom && $livewire->returnToResources ? $livewire->termPlanningReturnUrl() : RoomResource::getUrl('index', [
                        ...($livewire instanceof ViewRoom && $livewire->termId !== null ? ['termId' => $livewire->termId] : []),
                    ])),
                Action::make('weeklyUnavailableTimes')
                    ->label('Weekly unavailable times')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->url(fn (LivewireComponent $livewire): string => CalendarEventResource::getUrl('index', [
                        ...($livewire instanceof ViewRoom && $livewire->termId !== null ? ['termId' => $livewire->termId] : []),
                        'resourceView' => 'rooms',
                    ]))
                    ->visible(fn (): bool => CalendarEventResource::canViewAny()
                        && (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)),
            ])->visible(! $compact)->columnSpanFull(),
        ]);
    }
}
