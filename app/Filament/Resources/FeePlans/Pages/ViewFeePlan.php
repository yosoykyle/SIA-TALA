<?php

namespace App\Filament\Resources\FeePlans\Pages;

use App\Actions\Finance\CreateSuccessorFeePlan;
use App\Actions\Finance\PublishFeePlan;
use App\Actions\Finance\UpdateFeePlanDraft;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Models\FeePlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;

class ViewFeePlan extends ViewRecord
{
    protected static string $resource = FeePlanResource::class;

    public function getRecord(): FeePlan
    {
        $record = parent::getRecord();
        abort_unless($record instanceof FeePlan, 404);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('editDraft')
                ->label('Edit Draft')
                ->visible(fn (): bool => $this->getRecord()->state === FeePlan::StateDraft)
                ->fillForm(fn (): array => [
                    'charges' => $this->getRecord()->charges()->get()->map->only(['code', 'label', 'category', 'amount'])->all(),
                    'obligations' => $this->getRecord()->obligations()->get()->map->only(['code', 'label', 'purpose', 'amount', 'due_at', 'required_for_enrollment'])->all(),
                ])
                ->schema([
                    Repeater::make('charges')->schema([
                        TextInput::make('code')->required()->maxLength(40),
                        TextInput::make('label')->required()->maxLength(255),
                        TextInput::make('category')->maxLength(40),
                        TextInput::make('amount')->numeric()->minValue(0)->prefix('PHP')->required(),
                    ])->minItems(1)->columns(4),
                    Repeater::make('obligations')->schema([
                        TextInput::make('code')->required()->maxLength(40),
                        TextInput::make('label')->required()->maxLength(255),
                        TextInput::make('purpose')->required()->maxLength(40),
                        TextInput::make('amount')->numeric()->minValue(0)->prefix('PHP')->required(),
                        DateTimePicker::make('due_at')->required()->seconds(false),
                        Toggle::make('required_for_enrollment')->label('Enrollment requirement'),
                    ])->minItems(1)->columns(3),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(UpdateFeePlanDraft::class)->execute($this->getRecord(), $data['charges'], $data['obligations'], $actor);
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('publish')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->state === FeePlan::StateDraft)
                ->requiresConfirmation()
                ->schema([
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    DatePicker::make('authority_date')->required()->native(false),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(PublishFeePlan::class)->execute($this->getRecord(), $actor, $data['authority_reference'], CarbonImmutable::parse($data['authority_date'], config('app.timezone')));
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('createSuccessor')
                ->label('Create Successor Draft')
                ->visible(fn (): bool => $this->getRecord()->state === FeePlan::StatePublished)
                ->requiresConfirmation()
                ->action(function (): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $successor = app(CreateSuccessorFeePlan::class)->execute($this->getRecord(), $actor);
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $successor]));
                }),
        ];
    }
}
