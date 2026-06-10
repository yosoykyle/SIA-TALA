<?php

namespace App\Filament\Resources\FacultyAvailabilityChangeRequests\Pages;

use App\Actions\Scheduling\FacultyAvailabilityChangeRequestService;
use App\Filament\Resources\FacultyAvailabilityChangeRequests\FacultyAvailabilityChangeRequestResource;
use App\Models\FacultyAvailabilitySubmission;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFacultyAvailabilityChangeRequest extends CreateRecord
{
    protected static string $resource = FacultyAvailabilityChangeRequestResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        $submission = FacultyAvailabilitySubmission::query()->findOrFail($data['submission_id']);

        return app(FacultyAvailabilityChangeRequestService::class)->requestChange($actor, $submission, $data);
    }
}
