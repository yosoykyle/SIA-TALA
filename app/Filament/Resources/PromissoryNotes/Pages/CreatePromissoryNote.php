<?php

namespace App\Filament\Resources\PromissoryNotes\Pages;

use App\Filament\Resources\PromissoryNotes\PromissoryNoteResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePromissoryNote extends CreateRecord
{
    protected static string $resource = PromissoryNoteResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (in_array($data['status'] ?? null, ['approved', 'active'], true)) {
            $data['approved_by'] = Auth::id();
            $data['approved_at'] = now();
        }

        return $data;
    }
}
