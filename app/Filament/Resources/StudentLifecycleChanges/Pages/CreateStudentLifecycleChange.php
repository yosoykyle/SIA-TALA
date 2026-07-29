<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\StudentLifecycleChange;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateStudentLifecycleChange extends CreateRecord
{
    protected static string $resource = StudentLifecycleChangeResource::class;

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->label('Confirm and Record Approved Result');
    }

    /** @param array<string,mixed> $data */
    protected function handleRecordCreation(array $data): StudentLifecycleChange
    {
        try {
            return app(StudentLifecycleService::class)->record($data, auth()->user());
        } catch (StudentLifecycleRuleViolation $exception) {
            throw ValidationException::withMessages([
                'data.impact_confirmed' => $exception->getMessage(),
            ]);
        }
    }
}
