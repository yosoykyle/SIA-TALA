<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Models\User;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;

class CreateFacultyQualification extends CreateRecord
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
        return 'Add qualification';
    }

    public function getBreadcrumb(): string
    {
        return 'Add qualification';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Add qualification');
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel')->color('gray'),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()->label('Save & add another')->color('gray')] : []),
            $this->getCreateFormAction()->label('Add qualification'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return [...$data, 'recorded_by' => $actor->id, 'recorded_at' => now()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
