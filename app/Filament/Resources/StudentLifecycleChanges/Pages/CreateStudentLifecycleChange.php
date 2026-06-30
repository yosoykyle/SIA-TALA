<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\StudentLifecycleChange;
use Filament\Resources\Pages\CreateRecord;

class CreateStudentLifecycleChange extends CreateRecord
{
    protected static string $resource = StudentLifecycleChangeResource::class;

    /** @param array<string,mixed> $data */
    protected function handleRecordCreation(array $data): StudentLifecycleChange
    {
        return app(StudentLifecycleService::class)->record($data, auth()->user());
    }
}
