<?php

namespace App\Filament\Resources\ScheduleChanges\Pages;

use App\Filament\Resources\ScheduleChanges\ScheduleChangeResource;
use App\Models\SectionMeeting;
use App\Support\Scheduling\ScheduleChangePayload;
use Filament\Resources\Pages\CreateRecord;

class CreateScheduleChange extends CreateRecord
{
    protected static string $resource = ScheduleChangeResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $sectionMeeting = SectionMeeting::query()->find($data['section_meeting_id'] ?? null);

        return [
            ...ScheduleChangePayload::stripFormOnlyFields($data),
            'status' => 'proposed',
            'requested_by' => auth()->id(),
            'old_payload' => ScheduleChangePayload::fromSectionMeeting($sectionMeeting),
            'new_payload' => ScheduleChangePayload::fromFormData($data),
        ];
    }
}
