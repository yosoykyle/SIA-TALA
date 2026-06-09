<?php

namespace App\Filament\Resources\FacultyAvailabilitySubmissions\Pages;

use App\Actions\Scheduling\FacultyAvailabilityService;
use App\Filament\Resources\FacultyAvailabilitySubmissions\FacultyAvailabilitySubmissionResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFacultyAvailabilitySubmission extends CreateRecord
{
    protected static string $resource = FacultyAvailabilitySubmissionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return app(FacultyAvailabilityService::class)->submitAvailability($data, $actor);
    }
}
