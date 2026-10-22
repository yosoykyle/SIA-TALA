<?php

namespace App\Filament\Resources\GradeRosters\Actions;

use App\Actions\Grades\CurrentClassRosterOutput;
use App\Models\GradeRoster;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExportClassRosterAction extends Action
{
    /** @var array<string, mixed>|null */
    private ?array $rosterPreview = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->label('Export class roster')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->modalHeading('Export class roster')->modalDescription('Download current official membership for this class.')
            ->modalIcon(null)->modalWidth(Width::FourExtraLarge)
            ->extraModalWindowAttributes(['autofocus' => true, 'tabindex' => '-1'])->stickyModalHeader()->modalFooterActionsAlignment(Alignment::End)->stickyModalFooter()
            ->modalSubmitActionLabel('Download CSV')
            ->modalSubmitAction(fn (Action $action): Action => $action->hidden(fn (): bool => $this->preview()['error'] !== null))
            ->modalCancelActionLabel(fn (): string => $this->preview()['error'] !== null ? 'Close' : 'Cancel')
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('refreshRoster', arguments: ['refresh' => true])->label('Refresh roster')
                    ->visible(fn (): bool => $this->preview()['error'] !== null),
            ])
            ->schema([SchemaView::make('filament.pages.partials.class-roster-export')->viewData(fn (): array => $this->preview())])
            ->action(function (array $arguments): void {
                if ($arguments['refresh'] ?? false) {
                    $this->rosterPreview = null;
                    $this->halt();
                }
                try {
                    $snapshot = app(CurrentClassRosterOutput::class)->forRoster($this->roster(), $this->actor());
                } catch (HttpExceptionInterface $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                    $this->rosterPreview = null;
                    $this->halt();
                }
                $this->getLivewire()->redirect(route('grade-rosters.csv', $snapshot['roster']));
            });
    }

    /** @return array<string, mixed> */
    private function preview(): array
    {
        return $this->rosterPreview ??= app(CurrentClassRosterOutput::class)->preview($this->roster(), $this->actor());
    }

    private function roster(): GradeRoster
    {
        $roster = $this->getRecord();
        abort_unless($roster instanceof GradeRoster, 404);

        return $roster;
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
