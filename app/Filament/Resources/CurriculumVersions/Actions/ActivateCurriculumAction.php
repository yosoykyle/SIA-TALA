<?php

namespace App\Filament\Resources\CurriculumVersions\Actions;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Models\CurriculumVersion;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * One short native confirmation for activating a curriculum from any Catalog surface.
 *
 * The dialog states which intake starts using the plan, what it replaces, that assigned
 * students keep their plan, and which linked draft subjects become active with it.
 */
class ActivateCurriculumAction extends Action
{
    /** @var array<string, mixed>|null */
    private ?array $cachedImpact = null;

    private ?bool $cachedIsReady = null;

    public static function getDefaultName(): ?string
    {
        return 'activateCurriculum';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Activate curriculum')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color(fn (): string => $this->isReady() ? 'primary' : 'gray')
            ->modalIcon(null)
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading(fn (): string => 'Activate '.$this->curriculum()->name.'?')
            ->modalSubmitActionLabel('Activate curriculum')
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled(fn (): bool => $this->impact()['readiness_errors'] !== []))
            ->modalCancelActionLabel('Cancel')
            ->schema(fn (): array => [
                SchemaView::make('filament.resources.curriculum-versions.partials.activation-impact')
                    ->viewData(fn (): array => ['curriculum' => $this->curriculum(), 'impact' => $this->impact()]),
                Select::make('effective_entry_term_id')
                    ->label('First student intake')
                    ->helperText('Optional. The term when new students start following this curriculum.')
                    ->options(fn (): array => self::intakeOptions())
                    ->searchable()
                    ->visible(fn (): bool => $this->curriculum()->effective_entry_term_id === null),
            ])
            ->visible(function (): bool {
                $record = $this->getRecord();
                $actor = auth()->user();

                return $record instanceof CurriculumVersion
                    && $actor instanceof User
                    && $actor->can('activate', $record);
            })
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                try {
                    $activated = app(CurriculumVersionLifecycleService::class)->activate(
                        actor: $actor,
                        curriculumVersion: $this->curriculum(),
                        effectiveEntryTermId: filled($data['effective_entry_term_id'] ?? null) ? (int) $data['effective_entry_term_id'] : null,
                    );
                } catch (ValidationException $exception) {
                    $this->cachedImpact = null;
                    $this->cachedIsReady = null;
                    Notification::make()
                        ->title('Curriculum not activated')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $this->halt();

                    return;
                }

                $this->cachedImpact = null;
                $this->cachedIsReady = null;
                $this->record($activated);
                $livewire = $this->getLivewire();

                if (property_exists($livewire, 'record')) {
                    $livewire->record = $activated;
                }

                Notification::make()
                    ->title('Curriculum activated')
                    ->body('Students already assigned to a curriculum keep their current plan.')
                    ->success()
                    ->send();
            });
    }

    private function curriculum(): CurriculumVersion
    {
        $record = $this->getRecord();
        abort_unless($record instanceof CurriculumVersion, 404);

        return $record;
    }

    /** @return array<string, mixed> */
    private function impact(): array
    {
        return $this->cachedImpact ??= app(CurriculumVersionLifecycleService::class)->activationImpact($this->curriculum());
    }

    private function isReady(): bool
    {
        if ($this->cachedIsReady !== null) {
            return $this->cachedIsReady;
        }

        $record = $this->getRecord();
        $hasEntries = $record instanceof CurriculumVersion
            && ($record->relationLoaded('entries') ? $record->entries->isNotEmpty() : $record->entries()->exists());

        return $this->cachedIsReady = $record instanceof CurriculumVersion
            && $record->isEditableDraft()
            && $hasEntries
            && app(CurriculumVersionLifecycleService::class)->readinessErrors($record) === [];
    }

    /** @return array<int, string> */
    private static function intakeOptions(): array
    {
        return Term::query()
            ->with('academicYear')
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (Term $term): array => [
                $term->id => collect([$term->label, $term->academicYear?->label])->filter()->implode(' · '),
            ])
            ->all();
    }
}
