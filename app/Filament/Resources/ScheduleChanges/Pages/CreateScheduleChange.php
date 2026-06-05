<?php

namespace App\Filament\Resources\ScheduleChanges\Pages;

use App\Filament\Resources\ScheduleChanges\ScheduleChangeResource;
use App\Models\ScheduleChange;
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
        $data = ScheduleChange::validateTargetMeetingData($data);
        $sectionMeeting = SectionMeeting::query()->findOrFail($data['section_meeting_id']);

        return [
            ...ScheduleChangePayload::stripFormOnlyFields($data),
            'status' => 'proposed',
            'requested_by' => auth()->id(),
            'old_payload' => ScheduleChangePayload::fromSectionMeeting($sectionMeeting),
            'new_payload' => ScheduleChangePayload::fromFormData($data),
        ];
    }
}
