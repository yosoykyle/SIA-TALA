<?php

namespace App\Filament\Resources\FeePlans\Pages;

use App\Actions\Finance\CreateFeePlanDraft;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;

class ListFeePlans extends ListRecords
{
    protected static string $resource = FeePlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createDraft')
                ->label('Create Fee Plan Draft')
                ->schema([
                    Select::make('program_id')->label('Program')->options(Program::query()->where('is_active', true)->orderBy('code')->pluck('code', 'id'))->required()->searchable(),
                    Select::make('term_id')->label('Term')->options(Term::query()->orderByDesc('starts_on')->pluck('label', 'id'))->required()->searchable(),
                    Repeater::make('charges')->label('Fixed charges')->schema([
                        TextInput::make('code')->required()->maxLength(40),
                        TextInput::make('label')->required()->maxLength(255),
                        TextInput::make('category')->maxLength(40),
                        TextInput::make('amount')->numeric()->minValue(0)->prefix('PHP')->required(),
                    ])->minItems(1)->columns(4)->columnSpanFull(),
                    Repeater::make('obligations')->label('Payment obligations')->schema([
                        TextInput::make('code')->required()->maxLength(40),
                        TextInput::make('label')->required()->maxLength(255),
                        TextInput::make('purpose')->required()->maxLength(40),
                        TextInput::make('amount')->numeric()->minValue(0)->prefix('PHP')->required(),
                        DateTimePicker::make('due_at')->required()->seconds(false),
                        Toggle::make('required_for_enrollment')->label('Enrollment requirement'),
                    ])->minItems(1)->columns(3)->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $plan = app(CreateFeePlanDraft::class)->execute(
                        Program::query()->findOrFail($data['program_id']),
                        Term::query()->findOrFail($data['term_id']),
                        $data['charges'],
                        $actor,
                        $data['obligations'],
                    );
                    $this->redirect(FeePlanResource::getUrl('view', ['record' => $plan]));
                }),
        ];
    }
}
