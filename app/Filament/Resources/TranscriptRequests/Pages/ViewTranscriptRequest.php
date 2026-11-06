<?php

namespace App\Filament\Resources\TranscriptRequests\Pages;

use App\Filament\Pages\CompletionAndTor;
use App\Filament\Resources\TranscriptRequests\Concerns\InteractsWithTranscriptActions;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\TranscriptRequest;
use App\Models\User;
use Filament\Resources\Pages\ViewRecord;

class ViewTranscriptRequest extends ViewRecord
{
    use InteractsWithTranscriptActions;

    protected static string $resource = TranscriptRequestResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        if (auth()->user()?->hasRole(User::StaffRoleRegistrar)) {
            $this->redirect(CompletionAndTor::getUrl(['request' => $this->request()->id, 'find' => $this->request()->external_request_reference]));
        }
    }

    public function getTitle(): string
    {
        return 'TOR request · '.$this->request()->external_request_reference;
    }

    public function getBreadcrumbs(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleRegistrar)) {
            return [
                CompletionAndTor::getUrl() => 'Completion & TOR',
                TranscriptRequestResource::getUrl('index') => 'TOR requests',
                'Request details',
            ];
        }

        return parent::getBreadcrumbs();
    }

    protected function getHeaderActions(): array
    {
        return $this->transcriptActions();
    }

    private function request(): TranscriptRequest
    {
        $record = $this->getRecord();
        abort_unless($record instanceof TranscriptRequest, 404);

        return $record;
    }
}
