<?php

namespace App\Filament\Resources\Sections\Pages;

use App\Actions\Scheduling\SectionPlanningService;
use App\Filament\Pages\TermClasses;
use App\Filament\Resources\Sections\Schemas\SectionForm;
use App\Filament\Resources\Sections\SectionResource;
use App\Models\Section;
use App\Support\HasTermPlanningContext;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;

class ListSections extends ListRecords
{
    use HasTermPlanningContext;

    protected static string $resource = SectionResource::class;

    public function mount(): void
    {
        parent::mount();
        if ($this->termId !== null) {
            $this->redirect(TermClasses::getUrl(['termId' => $this->termId, ...($this->defaultAction === 'create' ? ['action' => 'addClass'] : [])]));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add a class')->url(null)
                ->schema(fn (Schema $schema): Schema => SectionForm::configure($schema))
                ->mutateDataUsing(fn (array $data): array => app(SectionPlanningService::class)->prepareForSave($this->termPlanningFormData([...$data, 'state' => Section::StatePlanned]))),
        ];
    }
}
