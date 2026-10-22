<?php

namespace App\Filament\Resources\FeePlans\Pages;

use App\Actions\Finance\CreateFeePlanDraft;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\FeePlans\Schemas\FeePlanForm;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

class ListFeePlans extends ListRecords
{
    protected static string $resource = FeePlanResource::class;

    public function getSubheading(): ?string
    {
        return 'Manage approved program fees and payment dates.';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        $schema = parent::content($schema);

        return $schema->components([SchemaView::make('filament.admin.fee-plans.styles'), ...$schema->getComponents()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createDraft')
                ->label('New draft')
                ->modalHeading('New fee plan draft')->modalDescription('Set the charges and when they must be paid. Approval is recorded before publication.')
                ->modalWidth(Width::FiveExtraLarge)->stickyModalHeader()->stickyModalFooter()->modalFooterActionsAlignment(Alignment::End)->modalSubmitActionLabel('Save draft')
                ->schema(FeePlanForm::components(includeScope: true))
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $rows = FeePlanForm::rows($data);
                    try {
                        $plan = app(CreateFeePlanDraft::class)->execute(
                            Program::query()->findOrFail($data['program_id']),
                            Term::query()->findOrFail($data['term_id']),
                            $rows['charges'],
                            $actor,
                            $rows['obligations'],
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Draft was not saved')->body(collect($exception->errors())->flatten()->first())->danger()->send();
                        throw $exception;
                    }
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $plan]));
                }),
        ];
    }
}
