<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Actions\Admissions\ChangeAdmissionCycle;
use App\Actions\Admissions\PublishAdmissionCycle;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionCycle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewAdmissionCycle extends ViewRecord
{
    protected static string $resource = AdmissionCycleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StateDraft),
            Action::make('publish')
                ->label('Publish cycle')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Publication reruns every readiness check. A failed source remains visible with its owner and recovery.')
                ->schema($this->authoritySchema(includeReason: false))
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StateDraft)
                ->action(fn (array $data): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(PublishAdmissionCycle::class)
                        ->execute($cycle, $actor, (string) $data['authority_reference']),
                    'Admission Cycle published',
                )),
            Action::make('close')
                ->label('Close cycle now')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->requiresConfirmation()
                ->schema($this->authoritySchema())
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished
                    && $this->cycle()->closes_at?->isFuture())
                ->action(fn (array $data): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->close($cycle, $actor, (string) $data['reason'], (string) $data['authority_reference']),
                    'Admission Cycle closed',
                )),
            Action::make('extend')
                ->label('Extend closing time')
                ->icon('heroicon-o-calendar-days')
                ->schema($this->dateChangeSchema())
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished
                    && $this->cycle()->closes_at?->isFuture())
                ->action(fn (array $data): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->extend($cycle, $actor, CarbonImmutable::parse((string) $data['closes_at']), (string) $data['reason'], (string) $data['authority_reference']),
                    'Admission Cycle extended',
                )),
            Action::make('reopen')
                ->label('Reopen cycle')
                ->icon('heroicon-o-lock-open')
                ->schema($this->dateChangeSchema())
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished
                    && ! $this->cycle()->closes_at?->isFuture())
                ->action(fn (array $data): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->reopen($cycle, $actor, CarbonImmutable::parse((string) $data['closes_at']), (string) $data['reason'], (string) $data['authority_reference']),
                    'Admission Cycle reopened',
                )),
            Action::make('cancel')
                ->label('Cancel cycle')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Cancellation stops new work and preserves every existing application and authority event.')
                ->schema($this->authoritySchema())
                ->visible(fn (): bool => $this->cycle()->state === AdmissionCycle::StatePublished)
                ->action(fn (array $data): mixed => $this->runCycleAction(
                    fn (AdmissionCycle $cycle, User $actor): AdmissionCycle => app(ChangeAdmissionCycle::class)
                        ->cancel($cycle, $actor, (string) $data['reason'], (string) $data['authority_reference']),
                    'Admission Cycle cancelled',
                )),
        ];
    }

    /** @return list<TextInput|Textarea> */
    private function authoritySchema(bool $includeReason = true): array
    {
        $schema = [
            TextInput::make('authority_reference')
                ->label('Authority reference')
                ->required()
                ->maxLength(255),
        ];

        if ($includeReason) {
            $schema[] = Textarea::make('reason')->required()->maxLength(1000);
        }

        return $schema;
    }

    /** @return list<DateTimePicker|TextInput|Textarea> */
    private function dateChangeSchema(): array
    {
        return [
            DateTimePicker::make('closes_at')
                ->label('New closing time')
                ->native(false)
                ->minDate(now())
                ->required(),
            ...$this->authoritySchema(),
        ];
    }

    private function cycle(): AdmissionCycle
    {
        $record = $this->getRecord();
        abort_unless($record instanceof AdmissionCycle, 404);

        return $record;
    }

    /** @param callable(AdmissionCycle, User): AdmissionCycle $operation */
    private function runCycleAction(callable $operation, string $successTitle): mixed
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation($this->cycle(), $actor);
            Notification::make()->title($successTitle)->success()->send();
            $this->refreshFormData(['state', 'opens_at', 'closes_at']);
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Admission Cycle action blocked')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        }

        return null;
    }
}
