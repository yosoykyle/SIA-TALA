<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Models\Enrollment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewEnrollment extends ViewRecord
{
    protected static string $resource = EnrollmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EnrollmentsTable::confirmPlacementAction(),
            Action::make('printCor')
                ->label('Print COR')
                ->icon('heroicon-o-printer')
                ->url(fn (): string => route('cor.print', $this->getRecord()))
                ->openUrlInNewTab()
                ->visible(function (): bool {
                    $record = $this->getRecord();
                    $user = auth()->user();

                    return $record instanceof Enrollment
                        && $record->status === 'officially_enrolled'
                        && $record->officially_enrolled_at !== null
                        && $user instanceof User
                        && $user->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAccounting]);
                }),
        ];
    }
}
