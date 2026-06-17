<?php

namespace App\Filament\Resources\PromissoryNotes\Pages;

use App\Actions\Finance\PromissoryNoteLifecycleService;
use App\Filament\Resources\PromissoryNotes\PromissoryNoteResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePromissoryNote extends CreateRecord
{
    protected static string $resource = PromissoryNoteResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return app(PromissoryNoteLifecycleService::class)->submit($data, $actor);
    }
}
