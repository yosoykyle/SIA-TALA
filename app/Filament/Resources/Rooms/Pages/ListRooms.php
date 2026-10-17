<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Filament\Resources\Rooms\RoomResource;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRooms extends ListRecords
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = RoomResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-rooms-list'];
    }

    public function getSubheading(): string
    {
        return 'Room records apply across Terms. Active rooms can be considered for scheduling; weekly unavailable times restrict when they can be used.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add room')->icon('heroicon-o-plus')->authorize('create'),
            Action::make('weeklyUnavailableTimes')
                ->label('Weekly unavailable times')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->url(fn (): string => CalendarEventResource::getUrl('index', [
                    ...($this->termId !== null ? ['termId' => $this->termId] : []),
                    'resourceView' => 'rooms',
                ]))
                ->visible(fn (): bool => CalendarEventResource::canViewAny()
                    && (auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)),
        ];
    }
}
