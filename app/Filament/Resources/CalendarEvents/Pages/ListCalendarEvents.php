<?php

namespace App\Filament\Resources\CalendarEvents\Pages;

use App\Filament\Pages\MyAvailability;
use App\Filament\Pages\TermCalendar;
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
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = CalendarEventResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-weekly-unavailable-list'];
    }

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

        return 'Prevent recurring meetings for a room, Faculty member or the school. Dated no-class exceptions belong in Calendar.';
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
            Action::make('calendar')
                ->label('Calendar')->icon('heroicon-o-calendar-days')->color('gray')
                ->url(fn (): string => TermCalendar::getUrl(['termId' => $this->termId]))
                ->visible(fn (): bool => TermCalendar::canAccess()),
            CreateAction::make()->label('Add unavailable time')->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false),
        ];
    }
}
