<?php

namespace App\Filament\Resources\Sections\RelationManagers;

use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DeliveryGroupsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'deliveryGroups';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make('Class delivery group')
                    ->schema([
                        TextInput::make('name')
                            ->label('Group name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('expected_count')
                            ->label('Expected learners')
                            ->required()
                            ->integer()
                            ->minValue(0),
                        Select::make('modality')
                            ->options(SectionDeliveryGroup::modalityOptions())
                            ->required(),
                        Select::make('state')
                            ->options(SectionDeliveryGroup::stateOptions())
                            ->default(SectionDeliveryGroup::StatePlanned)
                            ->required(),
                        KeyValue::make('delivery_override')
                            ->label('Delivery Override')
                            ->keyLabel('Override key')
                            ->valueLabel('Value')
                            ->helperText('Optional source-record override for later scheduling demand generation.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Group')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('expected_count')
                    ->label('Expected')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::stateOptions()[$state] ?? str($state)->headline()->toString())),
            ])
            ->filters([
                SelectFilter::make('modality')
                    ->options(SectionDeliveryGroup::modalityOptions()),
                SelectFilter::make('state')
                    ->options(SectionDeliveryGroup::stateOptions()),
            ])
            ->headerActions([
                CreateAction::make()->label('Add delivery group')->slideOver()
                    ->using(fn (array $data): Model => $this->saveDeliveryGroup($data)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->slideOver()
                    ->using(function (Model $record, array $data): Model {
                        /** @var SectionDeliveryGroup $record */
                        return $this->saveDeliveryGroup($data, $record);
                    }),
            ])
            ->toolbarActions([]);
    }

    /** @param array<string, mixed> $data */
    private function saveDeliveryGroup(array $data, ?SectionDeliveryGroup $group = null): SectionDeliveryGroup
    {
        try {
            return app(SectionDeliveryGroupService::class)->save($this->ownerSection(), $data, $group, $this->actor());
        } catch (ValidationException $exception) {
            Notification::make()->title('Delivery group could not be saved')->body(collect($exception->errors())->flatten()->first())->danger()->persistent()->send();
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(
                fn (array $messages, string $field): array => [$this->getMountedActionSchema()->getStatePath().'.'.$field => $messages],
            )->all());
        }
    }

    private function ownerSection(): Section
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Section) {
            throw new RuntimeException('Delivery groups can only be managed from a Section record.');
        }

        return $owner;
    }

    private function actor(): ?User
    {
        $actor = auth()->user();

        return $actor instanceof User ? $actor : null;
    }
}
