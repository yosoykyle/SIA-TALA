<?php

namespace App\Filament\Resources\ScheduleChanges\Pages;

use App\Filament\Resources\ScheduleChanges\ScheduleChangeResource;
use App\Models\SectionMeeting;
use App\Support\Scheduling\ScheduleChangePayload;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditScheduleChange extends EditRecord
{
    protected static string $resource = ScheduleChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $sectionMeeting = SectionMeeting::query()->find($data['section_meeting_id'] ?? null);

        return [
            ...ScheduleChangePayload::stripFormOnlyFields($data),
            'old_payload' => is_array($this->record->old_payload)
                ? ScheduleChangePayload::normalize($this->record->old_payload)
                : ScheduleChangePayload::fromSectionMeeting($sectionMeeting),
            'new_payload' => ScheduleChangePayload::fromFormData($data),
        ];
    }
}
