<?php

namespace App\Filament\Resources\Rooms\Schemas;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\Rooms\Pages\ViewRoom;
use App\Filament\Resources\Rooms\RoomResource;
use App\Models\Room;
use App\Models\User;
use App\Support\RoomEquipment;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Component as LivewireComponent;

class RoomInfolist
{
    public static function configure(Schema $schema, bool $compact = false): Schema
    {
        return $schema->components([
            Section::make('Room details')
                ->schema([
                    Group::make([
                        TextEntry::make('code')->label('Room code')->weight('bold')->copyable(),
                        TextEntry::make('room_type')
                            ->label('Room type')
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (Room::typeOptions()[$state] ?? str($state)->headline()->toString())),
                        TextEntry::make('name')->placeholder('-'),
                        TextEntry::make('capacity')->numeric()->suffix(' seats')->placeholder('-'),
                        TextEntry::make('building')->placeholder('-'),
                        TextEntry::make('is_active')
                            ->label('Status')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                            ->icon(fn (bool $state): Heroicon => $state ? Heroicon::CheckCircle : Heroicon::MinusCircle)
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    ])->columns(2),
                    Section::make('Equipment')
                        ->contained(false)
                        ->schema([
                            TextEntry::make('features.feature_key')
                                ->label('Equipment')
                                ->hiddenLabel()
                                ->formatStateUsing(fn (?string $state): string => RoomEquipment::label($state))
                                ->listWithLineBreaks()
                                ->placeholder('None recorded'),
                        ]),
                    Section::make('Notes')
                        ->contained(false)
                        ->schema([
                            TextEntry::make('notes')->hiddenLabel()->placeholder('No notes recorded'),
                        ]),
                    Section::make('Record details')
                        ->visible(! $compact)
                        ->contained(false)
                        ->schema([
                            TextEntry::make('created_at')
                                ->label('Created (Asia/Manila)')
                                ->dateTime('M j, Y, g:i A')
                                ->timezone('Asia/Manila'),
                            TextEntry::make('updated_at')
                                ->label('Last updated (Asia/Manila)')
                                ->dateTime('M j, Y, g:i A')
                                ->timezone('Asia/Manila'),
                        ])
                        ->columns(['default' => 1, 'md' => 2]),
                ])
                ->columns(1)
                ->divided()
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
