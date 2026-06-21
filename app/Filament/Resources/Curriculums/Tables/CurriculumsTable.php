<?php

namespace App\Filament\Resources\Curriculums\Tables;

use App\Actions\AcademicFoundation\CurriculumScopeReadinessService;
use App\Models\Curriculum;
use App\Models\Section;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;

class CurriculumsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('program.code')->label('Program')->searchable()->sortable(),
                TextColumn::make('version_name')->label('Version')->searchable()->sortable()->weight('bold'),
                TextColumn::make('effective_year')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                TextColumn::make('activated_at')->dateTime()->sortable()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('program_id')->label('Program')->relationship('program', 'name'),
                SelectFilter::make('is_active')->label('Active')->options([
                    '1' => 'Active',
                    '0' => 'Inactive',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                self::markScopeReadyAction(),
                self::markScopeNeedsReviewAction(),
                EditAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('effective_year', 'desc');
    }

    private static function markScopeReadyAction(): Action
    {
        return Action::make('markScopeReady')
            ->label('Mark scope ready')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->schema(self::readinessScopeSchema(requireReason: false))
            ->modalHeading('Mark curriculum scope ready')
            ->modalSubmitActionLabel('Mark ready')
            ->visible(fn (): bool => self::canReviewReadiness())
            ->action(fn (Curriculum $record, array $data) => self::transitionReadinessScope($record, $data, ready: true));
    }

    private static function markScopeNeedsReviewAction(): Action
    {
        return Action::make('markScopeNeedsReview')
            ->label('Send scope to review')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->schema(self::readinessScopeSchema(requireReason: true))
            ->modalHeading('Send curriculum scope to review')
            ->modalSubmitActionLabel('Send to review')
            ->visible(fn (): bool => self::canManageCurriculumData() || self::canReviewReadiness())
            ->action(fn (Curriculum $record, array $data) => self::transitionReadinessScope($record, $data, ready: false));
    }

    /**
     * @return array<int, Select|Textarea>
     */
    private static function readinessScopeSchema(bool $requireReason): array
    {
        return [
            Select::make('year_level')
                ->label('Year Level')
                ->options(Section::yearLevelOptions())
                ->required(),
            Select::make('curriculum_period')
                ->label('Curriculum Period')
                ->options(Section::curriculumPeriodOptions())
                ->required(),
            Textarea::make('reason')
                ->label('Reason')
                ->required($requireReason)
                ->maxLength(2000)
                ->rows(4),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function transitionReadinessScope(Curriculum $record, array $data, bool $ready): void
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return;
        }

        try {
            $service = app(CurriculumScopeReadinessService::class);
            $scope = $service->scopeFor(
                $record,
                (string) $data['year_level'],
                (string) $data['curriculum_period'],
            );

            if ($ready) {
                $service->markReady($scope, $actor, $data['reason'] ?? null);
            } else {
                $service->markNeedsReview($scope, $actor, $data['reason'] ?? null);
            }

            Notification::make()
                ->title($ready ? 'Curriculum scope ready' : 'Curriculum scope sent to review')
                ->success()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Readiness action failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function canReviewReadiness(): bool
    {
        $user = auth()->user();

        return ($user?->hasRole('academic-head') ?? false)
            && ($user?->can('authorize-overrides') ?? false);
    }

    private static function canManageCurriculumData(): bool
    {
        return auth()->user()?->can('manage-curricula') ?? false;
    }
}
