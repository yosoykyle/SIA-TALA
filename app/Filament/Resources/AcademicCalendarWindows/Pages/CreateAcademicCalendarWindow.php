<?php

namespace App\Filament\Resources\AcademicCalendarWindows\Pages;

use App\Filament\Concerns\HasAcademicSetupBreadcrumbs;
use App\Filament\Resources\AcademicCalendarWindows\AcademicCalendarWindowResource;
use App\Models\CalendarEvent;
use Filament\Resources\Pages\CreateRecord;

class CreateAcademicCalendarWindow extends CreateRecord
{
    use HasAcademicSetupBreadcrumbs;

    protected static string $resource = AcademicCalendarWindowResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            ...$this->academicSetupParentBreadcrumb(),
            AcademicCalendarWindowResource::getUrl('index') => 'Academic Calendar Windows',
            'Add calendar window',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'room_id' => null,
            'faculty_user_id' => null,
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'blocks_scheduling' => false,
        ];
    }
}
