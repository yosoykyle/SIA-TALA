<?php

namespace App\Filament\Resources\Sections\RelationManagers;

use App\Actions\Scheduling\SectionDeliveryGroupService;
use App\Models\DeliveryPattern;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionDeliveryGroup;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class DeliveryGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveryGroups';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FormSection::make('Delivery Group')
                    ->schema([
                        Select::make('delivery_pattern_id')
                            ->label('Delivery Pattern')
                            ->options(fn (?SectionDeliveryGroup $record): array => self::deliveryPatternOptions($record?->delivery_pattern_id))
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('name')
                            ->label('Group Name')
                            ->required()
                            ->maxLength(255),
                        Select::make('modality')
                            ->options(SectionDeliveryGroup::modalityOptions())
                            ->live()
                            ->required(),
                        TextInput::make('capacity')
                            ->required()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(Section::MaxRescueSeats),
                        Select::make('status')
                            ->options(SectionDeliveryGroup::statusOptions())
                            ->required()
                            ->default(SectionDeliveryGroup::StatusActive),
                        Select::make('room')
                            ->label('Fixed Room')
                            ->options(fn (?SectionDeliveryGroup $record): array => Room::selectOptions($record?->room))
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => SectionDeliveryGroup::modalityRequiresRoom($get('modality')))
                            ->visible(fn (Get $get): bool => SectionDeliveryGroup::modalityRequiresRoom($get('modality'))),
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
                TextColumn::make('deliveryPattern.code')
                    ->label('Pattern')
                    ->searchable(),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('capacity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('assigned_count')
                    ->label('Assigned')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('available_seats')
                    ->label('Available')
                    ->state(fn (SectionDeliveryGroup $record): int => $record->availableSeats())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
                IconColumn::make('room_required')
                    ->boolean(),
                TextColumn::make('room')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionDeliveryGroup::statusOptions()[$state] ?? str($state)->headline()->toString())),
            ])
            ->filters([
                SelectFilter::make('modality')
                    ->options(SectionDeliveryGroup::modalityOptions()),
                SelectFilter::make('status')
                    ->options(SectionDeliveryGroup::statusOptions()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data): Model {
                        return app(SectionDeliveryGroupService::class)->save(
                            $this->ownerSection(),
                            $data,
                            null,
                            $this->actor(),
                        );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->using(function (Model $record, array $data): Model {
                        /** @var SectionDeliveryGroup $record */
                        return app(SectionDeliveryGroupService::class)->save(
                            $this->ownerSection(),
                            $data,
                            $record,
                            $this->actor(),
                        );
                    }),
            ])
            ->toolbarActions([]);
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

    /**
     * @return array<int, string>
     */
    private static function deliveryPatternOptions(mixed $currentPatternId = null): array
    {
        return DeliveryPattern::query()
            ->where(function ($query) use ($currentPatternId): void {
                $query->where('is_active', true);

                if (filled($currentPatternId)) {
                    $query->orWhereKey((int) $currentPatternId);
                }
            })
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->mapWithKeys(fn (DeliveryPattern $pattern): array => [
                $pattern->id => collect([
                    $pattern->code,
                    "v{$pattern->version}",
                    $pattern->name,
                    $pattern->modality === null ? 'Generic' : (DeliveryPattern::modalityOptions()[$pattern->modality] ?? $pattern->modality),
                    $pattern->is_active ? null : 'inactive',
                ])->filter()->implode(' | '),
            ])
            ->all();
    }
}
