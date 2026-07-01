<?php

namespace App\Filament\Resources\CalendarEvents\Pages;

use App\Filament\Resources\CalendarEvents\CalendarEventResource;
use App\Models\CalendarEvent;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use LogicException;

class EditCalendarEvent extends EditRecord
{
    protected static string $resource = CalendarEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $actor */
        $actor = auth()->user();

        if ($actor->hasAnyRole([User::StaffRoleFaculty, User::StaffRoleAcademicHead])) {
            $data['event_type'] = CalendarEvent::TypeUnavailable;
            $data['scope_type'] = CalendarEvent::ScopeFaculty;
        }

        if ($actor->hasRole(User::StaffRoleFaculty)) {
            $data['faculty_user_id'] = $actor->id;
            $data['state'] = CalendarEvent::StateActive;
        }

        $record = $this->getRecord();

        if (! $record instanceof CalendarEvent) {
            throw new LogicException('The scheduling block record is unavailable.');
        }

        $scope = (string) ($data['scope_type'] ?? $record->scope_type);

        return [
            ...$data,
            'event_type' => (string) ($data['event_type'] ?? $record->event_type),
            'scope_type' => $scope,
            'room_id' => $scope === CalendarEvent::ScopeRoom ? ($data['room_id'] ?? null) : null,
            'faculty_user_id' => $scope === CalendarEvent::ScopeFaculty ? ($data['faculty_user_id'] ?? null) : null,
            'process_key' => CalendarEvent::ProcessMasterSchedule,
            'start_at' => null,
            'end_at' => null,
            'blocks_scheduling' => true,
            'state' => (string) ($data['state'] ?? CalendarEvent::StateActive),
            'authority' => $actor->name.' ('.($actor->getRoleNames()->first() ?? 'authorized staff').')',
        ];
    }
}
