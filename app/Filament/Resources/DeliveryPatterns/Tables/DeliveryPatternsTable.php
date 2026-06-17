<?php

namespace App\Filament\Resources\DeliveryPatterns\Tables;

use App\Actions\Scheduling\DeliveryPatternService;
use App\Models\DeliveryPattern;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class DeliveryPatternsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('sectionDeliveryGroups'))
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->label('Version')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Generic' : (DeliveryPattern::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('subject_routing')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (DeliveryPattern::subjectRoutingOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('section_delivery_groups_count')
                    ->label('Groups')
                    ->counts('sectionDeliveryGroups')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                IconColumn::make('is_frozen')
                    ->label('Frozen')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('modality')
                    ->options(DeliveryPattern::modalityOptions()),
                SelectFilter::make('subject_routing')
                    ->options(DeliveryPattern::subjectRoutingOptions()),
                SelectFilter::make('enforcement_level')
                    ->options(DeliveryPattern::enforcementLevelOptions()),
            ])
            ->defaultSort('code')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::cloneVersionAction(),
            ])
            ->toolbarActions([]);
    }

    private static function cloneVersionAction(): Action
    {
        return Action::make('cloneVersion')
            ->label('Clone Version')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('info')
            ->schema([
                TextInput::make('name')
                    ->label('New Version Name')
                    ->maxLength(255),
            ])
            ->modalHeading('Clone Delivery Pattern')
            ->modalDescription('Creates the next version for this pattern code. Historical versions remain frozen for audit and schedule evidence.')
            ->modalSubmitActionLabel('Create Version')
            ->action(function (array $data, DeliveryPattern $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                try {
                    $clone = app(DeliveryPatternService::class)->cloneNewVersion(
                        $record,
                        $actor,
                        filled($data['name'] ?? null) ? ['name' => $data['name']] : [],
                    );

                    Notification::make()
                        ->title('Delivery pattern cloned')
                        ->body("Created {$clone->displayLabel()}.")
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Clone blocked')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
