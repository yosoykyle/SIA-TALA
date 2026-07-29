<?php

namespace App\Filament\Resources\ImportBatches\Pages;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\ImportBatches\ImportBatchDownloadActions;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Models\CurriculumVersion;
use App\Models\ImportBatch;
use App\Models\Program;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewImportBatch extends ViewRecord
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reviewCurriculumDraft')
                ->label('Review Curriculum Draft')
                ->icon(Heroicon::OutlinedAcademicCap)
                ->url(fn (): ?string => $this->postedCurriculum() instanceof CurriculumVersion
                    ? CurriculumVersionResource::getUrl('review', ['record' => $this->postedCurriculum()])
                    : null)
                ->visible(fn (): bool => $this->postedCurriculum() instanceof CurriculumVersion),
            ImportBatchDownloadActions::validationFindings(),
            ImportBatchDownloadActions::source(),
        ];
    }

    private function postedCurriculum(): ?CurriculumVersion
    {
        $batch = $this->getRecord();

        if (! $batch instanceof ImportBatch
            || $batch->type !== ImportBatch::TypeCurriculum
            || $batch->state !== ImportBatch::StatePosted) {
            return null;
        }

        $details = $batch->getAttribute('validation_details');
        $rows = is_array($details) ? ($details['rows'] ?? []) : [];
        $firstRow = is_array($rows) ? ($rows[0] ?? null) : null;
        $values = is_array($firstRow) ? ($firstRow['values'] ?? null) : null;

        if (! is_array($values)) {
            return null;
        }

        $programCode = strtoupper(trim((string) ($values['program_code'] ?? '')));
        $versionCode = trim((string) ($values['curriculum_version_code'] ?? ''));
        $programId = Program::query()->where('code', $programCode)->value('id');

        if ($programId === null || $versionCode === '') {
            return null;
        }

        return CurriculumVersion::query()
            ->where('program_id', $programId)
            ->where('version_code', $versionCode)
            ->first();
    }
}
