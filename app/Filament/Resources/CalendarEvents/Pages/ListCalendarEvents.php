<?php

namespace App\Filament\Resources\CalendarEvents\Pages;

use App\Filament\Pages\MyAvailability;
use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListCalendarEvents extends ListRecords
{
    use HasTermPlanningContext {
        getBreadcrumbs as protected termPlanningBreadcrumbs;
        getSubheading as protected termPlanningSubheading;
    }

    protected static string $resource = CalendarEventResource::class;

    /** @return array<string> */
    public function getBreadcrumbs(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleFaculty)) {
            return [
                MyAvailability::getUrl(['termId' => $this->termId]) => 'My Availability',
                'Historical unavailable-time records',
            ];
        }

        return $this->termPlanningBreadcrumbs();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (auth()->user()?->hasRole(User::StaffRoleFaculty)) {
            return $this->termPlanningContextDescription().'Read-only earlier unavailable-time records belonging to you. Record new declarations and corrections in My Availability.';
        }

        return $this->termPlanningSubheading();
    }

    protected function getHeaderActions(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleFaculty)) {
            return [
                Action::make('returnToMyAvailability')
                    ->label('Back to My Availability')
                    ->icon('heroicon-o-arrow-left')
                    ->color('gray')
                    ->url(MyAvailability::getUrl(['termId' => $this->termId])),
            ];
        }

        return [
            CreateAction::make(),
        ];
    }
}
