<?php

namespace App\Filament\Resources\TranscriptRequests\Pages;

use App\Filament\Pages\CompletionAndTor;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;

class ListTranscriptRequests extends ListRecords
{
    protected static string $resource = TranscriptRequestResource::class;

    public function mount(): void
    {
        parent::mount();
        if (auth()->user()?->hasRole(User::StaffRoleRegistrar)) {
            $this->redirect(CompletionAndTor::getUrl());
        }
    }

    public function getTitle(): string
    {
        return auth()->user()?->hasRole(User::StaffRoleAccounting)
            ? 'TOR restrictions'
            : 'TOR requests';
    }

    public function getSubheading(): ?string
    {
        return auth()->user()?->hasRole(User::StaffRoleAccounting)
            ? 'Open a request to record or resolve an actual Accounting restriction. Requests without restrictions need no clearance entry.'
            : 'Prepare and issue requested transcripts. Each request keeps its original date and 30-day due date while blockers are resolved.';
    }

    public function getBreadcrumbs(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleRegistrar)) {
            return [
                CompletionAndTor::getUrl() => 'Completion & TOR',
                'TOR requests',
            ];
        }

        if (auth()->user()?->hasRole(User::StaffRoleAccounting)) {
            return [
                EnrollmentResource::getUrl('index') => 'Overview',
                EnrollmentResource::getUrl('index', ['tab' => 'accounts']) => 'Student accounts',
                'TOR restrictions',
            ];
        }

        return parent::getBreadcrumbs();
    }
}
