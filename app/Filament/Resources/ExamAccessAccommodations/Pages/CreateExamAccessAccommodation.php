<?php

namespace App\Filament\Resources\ExamAccessAccommodations\Pages;

use App\Actions\Finance\ExamAccessAccommodationService;
use App\Filament\Resources\ExamAccessAccommodations\ExamAccessAccommodationResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExamAccessAccommodation extends CreateRecord
{
    protected static string $resource = ExamAccessAccommodationResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        if (isset($data['evidence_path']) && is_array($data['evidence_path'])) {
            $data['evidence_path'] = reset($data['evidence_path']) ?: null;
        }

        return app(ExamAccessAccommodationService::class)->submit($data, $actor);
    }
}
