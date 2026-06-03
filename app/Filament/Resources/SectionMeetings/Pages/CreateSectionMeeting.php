<?php

namespace App\Filament\Resources\SectionMeetings\Pages;

use App\Actions\Scheduling\SectionMeetingAssignmentService;
use App\Filament\Resources\SectionMeetings\SectionMeetingResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateSectionMeeting extends CreateRecord
{
    protected static string $resource = SectionMeetingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $registrar = auth()->user();

        if (! $registrar instanceof User) {
            throw ValidationException::withMessages([
                'committed_by' => 'An authenticated Registrar is required to commit a schedule.',
            ]);
        }

        return app(SectionMeetingAssignmentService::class)->prepareForCreate($data, $registrar);
    }
}
