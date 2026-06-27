<?php

namespace App\Filament\Resources\ApplicantIntakes\Pages;

use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Models\ApplicantIntake;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewApplicantIntake extends ViewRecord
{
    protected static string $resource = ApplicantIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadIdentityDocument')
                ->label('Download Identity Document')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => filled($this->applicantIntake()->identity_document_url))
                ->action(fn (): StreamedResponse => Storage::disk('local')->download(
                    $this->applicantIntake()->identity_document_url,
                )),
        ];
    }

    private function applicantIntake(): ApplicantIntake
    {
        $record = $this->getRecord();
        abort_unless($record instanceof ApplicantIntake, 404);

        return $record;
    }
}
