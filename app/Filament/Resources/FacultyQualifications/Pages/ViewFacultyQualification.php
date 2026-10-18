<?php

namespace App\Filament\Resources\FacultyQualifications\Pages;

use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Support\HasTermPlanningContext;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFacultyQualification extends ViewRecord
{
    use HasTermPlanningContext {
        getPageClasses as protected getTermPlanningPageClasses;
    }

    protected static string $resource = FacultyQualificationResource::class;

    public function getPageClasses(): array
    {
        return [...$this->getTermPlanningPageClasses(), 'tala-qualification-detail'];
    }

    public function getTitle(): string
    {
        $record = $this->getRecord();
        $title = $record->course?->specifications()
            ->where('state', CourseSpecification::StateActive)
            ->latest('id')
            ->value('title');

        return filled($title) ? $title : 'Faculty qualification';
    }

    public function getSubheading(): string
    {
        /** @var FacultyQualification $record */
        $record = $this->getRecord();

        return 'Faculty qualification · '.($record->course?->code ?? 'Course code unavailable');
    }

    public function getBreadcrumb(): string
    {
        return 'Qualification details';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Edit qualification'),
        ];
    }
}
