<?php

namespace App\Filament\Resources\EnrollmentSubjects\Pages;

use App\Filament\Resources\EnrollmentSubjects\EnrollmentSubjectResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEnrollmentSubject extends EditRecord
{
    protected static string $resource = EnrollmentSubjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
