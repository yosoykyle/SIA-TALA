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
use Illuminate\Validation\ValidationException;
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
                TextColumn::make('course_and_section')
                    ->label('Class requirement')
                    ->state(fn (SchedulingDemand $record): string => collect([
                        $record->courseComponent?->courseSpecification?->course?->code,
                        $record->sectionDeliveryGroup?->section?->code,
                    ])->filter()->implode(' · '))
                    ->description(fn (SchedulingDemand $record): string => collect([
                        $record->courseComponent?->courseSpecification?->title,
                        $record->sectionDeliveryGroup?->name,
                    ])->filter()->implode(' · '))
                    ->searchable([
                        'courseComponent.courseSpecification.course.code',
                        'sectionDeliveryGroup.section.code',
                    ])
                    ->weight('bold')
                    ->wrap(),
                TextColumn::make('requirement_summary')
                    ->label('Meeting requirement')
                    ->state(fn (SchedulingDemand $record): string => collect([
                        filled($record->courseComponent?->component_type)
                            ? str($record->courseComponent->component_type)->headline()
                            : null,
                        "{$record->required_duration_minutes} minutes",
                        TermOffering::modalityOptions()[$record->modality] ?? str($record->modality)->headline(),
                    ])->filter()->implode(' · '))
                    ->wrap(),
                TextColumn::make('termOffering.term.label')
                    ->label('Term')
                    ->searchable()
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('demand_key')
                    ->label('Technical requirement key')
                    ->toggleable(isToggledHiddenByDefault: true),
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
            ->toolbarActions([])
            ->stackedOnMobile()
            ->emptyStateHeading('No schedule requirements are recorded')
            ->emptyStateDescription('Complete offerings, sections, delivery groups, rooms, and faculty inputs, then generate the term schedule requirements.');
    }

    private static function generateForTermAction(): Action
    {
        return Action::make('generateForTerm')
            ->label('Generate Schedule Requirements')
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
                        ->title('Schedule requirements generated')
                        ->body("{$summary['total']} requirements checked; {$summary['ready']} ready, {$summary['action_required']} need source review.")
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();

                    Notification::make()
                        ->title('Schedule requirement generation blocked')
                        ->body(is_string($message) ? $message : 'Review the scheduling source data and try again.')
                        ->danger()
                        ->persistent()
                        ->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Schedule requirement generation failed')
                        ->body('TALA could not generate the schedule requirements. Try again or ask the System Administrator to review the application log.')
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }
}
