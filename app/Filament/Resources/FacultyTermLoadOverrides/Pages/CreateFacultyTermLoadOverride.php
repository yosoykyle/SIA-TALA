<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Pages;

use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use App\Models\Term;
use App\Support\HasTermPlanningContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Alignment;
use Illuminate\Validation\ValidationException;

class CreateFacultyTermLoadOverride extends CreateRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyTermLoadOverrideResource::class;

    /** @return array<string> */
    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-load-exception-form'];
    }

    public function getTitle(): string
    {
        return 'Add load exception';
    }

    public function getBreadcrumb(): string
    {
        return 'Add load exception';
    }

    public function getSubheading(): string
    {
        return 'Record the approved teaching-unit limit for one teacher in this Term.';
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction()->label('Cancel')->color('gray'),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()->label('Save & add another')->color('gray')] : []),
            $this->getCreateFormAction()->label('Add load exception'),
        ];
    }

    public function getFormActionsAlignment(): string|Alignment
    {
        return Alignment::End;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->termPlanningFormData($data);
        $term = Term::query()->find($data['term_id'] ?? null);

        if ($term === null || $term->default_max_units === null) {
            throw ValidationException::withMessages(['data.term_id' => 'Record this Term’s default teaching load before adding a Faculty load exception.']);
        }

        return [...$data, 'default_max_units_snapshot' => $term->default_max_units];
    }

    protected function getRedirectUrl(): string
    {
        return $this->termId === null ? parent::getRedirectUrl() : $this->getResourceUrl('index');
    }
}
