<?php

namespace App\Filament\Resources\Programs\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\CatalogCurriculaWorkbench;
use App\Filament\Resources\Programs\ProgramResource;
use App\Filament\Resources\Programs\RelationManagers\CurriculumVersionsRelationManager;
use App\Filament\Resources\Programs\Schemas\ProgramForm;
use App\Models\Program;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class ViewProgram extends ViewRecord
{
    protected static string $resource = ProgramResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    public function getTitle(): string
    {
        $program = $this->getRecord();

        return $program instanceof Program ? $program->name : parent::getTitle();
    }

    public function getSubheading(): ?string
    {
        $program = $this->getRecord();

        if (! $program instanceof Program) {
            return null;
        }

        return collect([$program->code, $program->duration_years ? $program->duration_years.' years' : null, $program->is_active ? null : 'Inactive'])->filter()->implode(' · ');
    }

    public function getBreadcrumbs(): array
    {
        $program = $this->getRecord();
        $label = $program instanceof Program ? (string) $program->code : 'Program';
        $params = array_filter([
            'oversightTermId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [
            CatalogCurriculaWorkbench::getUrl($params) => 'Catalog & Curricula',
            ProgramResource::getUrl('index', $params) => 'Programs',
            $label,
        ];
    }

    public function content(Schema $schema): Schema
    {
        $parameters = ['ownerRecord' => $this->getRecord(), 'pageClass' => static::class];

        return $schema->components([
            Livewire::make(CurriculumVersionsRelationManager::class, $parameters)->key('program-curricula'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $program = $this->getRecord();
        $oversightParameters = array_filter([
            'termId' => $this->oversightTermId,
        ], fn (mixed $value): bool => $value !== null);

        return [
            Action::make('returnToOversight')
                ->label('Return to Academic Oversight')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(AcademicApprovals::getUrl($oversightParameters))
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false),
            EditAction::make()
                ->label('Edit program')
                ->modal()
                ->modalHeading('Edit program')
                ->modalDescription('Update program code, name, length, or active status.')
                ->modalWidth(Width::TwoExtraLarge)
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->schema(fn (Schema $schema): Schema => ProgramForm::configure($schema))
                ->visible(fn (): bool => auth()->user()?->can('update', $program) ?? false),
        ];
    }
}
