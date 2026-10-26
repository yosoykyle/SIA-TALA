<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Alignment;

class EditFacultyQualification extends EditRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyQualificationResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-qualification-form'];
    }

    public function getTitle(): string
    {
        return 'Edit qualification';
    }

    public function getSubheading(): string
    {
        $record = $this->getRecord();
        $courseTitle = $record->course?->specifications()
            ->where('state', CourseSpecification::StateActive)
            ->latest('id')
            ->value('title');

        return collect([$courseTitle ?? $record->course?->code, $record->faculty?->name])->filter()->implode(' · ');
    }

    public function getBreadcrumb(): string
    {
        return 'Edit qualification';
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('View details'),
        ];
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel')->color('gray'),
            $this->getSaveFormAction()->label('Save changes'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        abort_unless($record instanceof FacultyQualification, 404);

        return [...$data, 'recorded_by' => $record->recorded_by, 'recorded_at' => $record->recorded_at];
    }
}
