<?php

namespace App\Filament\Resources\TranscriptRequests\Pages;

use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;

class ListTranscriptRequests extends ListRecords
{
    protected static string $resource = TranscriptRequestResource::class;

    public function getTitle(): string
    {
        return auth()->user()?->hasRole(User::StaffRoleAccounting)
            ? 'TOR Clearance'
            : 'TOR Requests & Issuance';
    }
}
