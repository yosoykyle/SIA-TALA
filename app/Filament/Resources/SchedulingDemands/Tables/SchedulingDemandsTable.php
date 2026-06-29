<?php

namespace App\Filament\Resources\SchedulingDemands\Tables;

use App\Actions\Scheduling\GenerateSchedulingDemand;
use App\Models\SchedulingDemand;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class SchedulingDemandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with([
                'termOffering.term',
                'termOffering.curriculumEntry.courseSpecification.course',
                'courseComponent.courseSpecification.course',
                'sectionDeliveryGroup.section',
                'fixedFaculty',
                'fixedRoom',
            ]))
            ->columns([
                TextColumn::make('termOffering.term.label')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sectionDeliveryGroup.section.code')
                    ->label('Section')
                    ->searchable(),
                TextColumn::make('sectionDeliveryGroup.name')
                    ->label('Delivery Group')
                    ->searchable(),
                TextColumn::make('courseComponent.courseSpecification.course.code')
                    ->label('Subject')
                    ->searchable(),
                TextColumn::make('courseComponent.courseSpecification.title')
                    ->label('Title')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('courseComponent.component_type')
                    ->label('Component')
                    ->badge(),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (TermOffering::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('required_duration_minutes')
                    ->label('Minutes')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('validation_state')
                    ->label('Readiness')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SchedulingDemand::validationStateOptions()[$state] ?? str($state)->headline()->toString()))
                    ->color(fn (?string $state): string => $state === null ? 'gray' : (SchedulingDemand::validationStateColors()[$state] ?? 'gray')),
                TextColumn::make('readiness_findings_count')
                    ->label('Findings')
                    ->state(fn (SchedulingDemand $record): int => count($record->readinessFindings()))
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'success' : 'warning'),
                TextColumn::make('readiness_checked_at')
                    ->label('Checked')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('term_id')
                    ->label('Term')
                    ->options(fn (): array => Term::query()
                        ->orderByDesc('starts_on')
                        ->orderBy('label')
                        ->pluck('label', 'id')
                        ->all())
                    ->query(fn ($query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn ($query, mixed $termId) => $query->whereHas('termOffering', fn ($query) => $query->where('term_id', $termId)),
                    )),
                SelectFilter::make('validation_state')
                    ->options(SchedulingDemand::validationStateOptions()),
                SelectFilter::make('modality')
                    ->options(TermOffering::modalityOptions()),
            ])
            ->defaultSort('readiness_checked_at', 'desc')
            ->headerActions([
                self::generateForTermAction(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    private static function generateForTermAction(): Action
    {
        return Action::make('generateForTerm')
            ->label('Generate for Term')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('primary')
            ->schema([
                Select::make('term_id')
                    ->label('Term')
                    ->options(fn (): array => Term::query()
                        ->orderByDesc('starts_on')
                        ->orderBy('label')
                        ->pluck('label', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->visible(fn (): bool => auth()->user()?->can('create', SchedulingDemand::class) ?? false)
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    return;
                }

                $term = Term::query()->findOrFail((int) $data['term_id']);

                try {
                    $summary = app(GenerateSchedulingDemand::class)->forTerm($actor, $term);

                    Notification::make()
                        ->title('Scheduling demand generated')
                        ->body("{$summary['total']} demand rows checked; {$summary['ready']} ready, {$summary['action_required']} need source review.")
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Scheduling demand generation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
