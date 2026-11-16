<?php

namespace App\Filament\Resources\FeePlans\Tables;

use App\Actions\Finance\AccountingDisplay;
use App\Actions\Finance\PublishFeePlan;
use App\Filament\Resources\FeePlans\FeePlanResource;
use App\Filament\Resources\FeePlans\Pages\ListFeePlans;
use App\Models\FeePlan;
use App\Models\Program;
use App\Models\Term;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

class FeePlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->deferFilters(false)
            ->extraAttributes([
                'class' => 'tala-data-table accounting-records-table fee-plans-table',
            ])
            ->modifyQueryUsing(fn (Builder $query, ListFeePlans $livewire): Builder => ($livewire->feePlanGateActive()
                ? $query->whereRaw('1 = 0')
                : $query)
                ->with(['program', 'term.academicYear', 'charges', 'obligations'])
                ->withSum('charges', 'amount')
                ->withSum(['obligations as enrollment_payment_amount' => fn (Builder $query): Builder => $query->where('required_for_enrollment', true)], 'amount'))
            ->columns([
                TextColumn::make('program.name')->label('Program / term')->weight('semibold')->wrap()
                    ->extraHeaderAttributes(['class' => 'fee-plans-col-program'])
                    ->extraCellAttributes(['class' => 'fee-plans-col-program'])
                    ->description(fn (FeePlan $record): ?string => collect([$record->term?->academicYear?->label, $record->term?->label])->filter()->implode(' · ') ?: null),
                TextColumn::make('state')->label('Status')->badge()->wrap()
                    ->extraHeaderAttributes(['class' => 'fee-plans-col-status'])
                    ->extraCellAttributes(['class' => 'fee-plans-col-status'])
                    ->formatStateUsing(fn (string $state): string => AccountingDisplay::feePlanStatusLabel($state))
                    ->color(fn (string $state): string => AccountingDisplay::feePlanStatusColor($state))
                    ->description(fn (FeePlan $record): string => $record->state === FeePlan::StateDraft
                        ? (self::draftReadiness($record) ?? 'Ready for approval')
                        : 'Updated '.($record->published_at ?? $record->updated_at)?->timezone(config('app.display_timezone'))->format('j M Y')),
                TextColumn::make('charges_sum_amount')->label('Total charges')
                    ->extraHeaderAttributes(['class' => 'fee-plans-col-money'])
                    ->extraCellAttributes(['class' => 'fee-plans-col-money'])
                    ->formatStateUsing(fn ($state): string => AccountingDisplay::money($state, 'Not set'))->placeholder('Not set')->alignEnd(),
                TextColumn::make('enrollment_payment_amount')->label('Enrollment payment')
                    ->extraHeaderAttributes(['class' => 'fee-plans-col-money'])
                    ->extraCellAttributes(['class' => 'fee-plans-col-money'])
                    ->formatStateUsing(fn ($state): string => AccountingDisplay::money($state, 'Not set'))->placeholder('Not set')->alignEnd(),
            ])
            ->searchable([fn (Builder $query, string $search): Builder => $query->where(fn (Builder $scope): Builder => $scope
                ->where('authority_reference', 'like', "%{$search}%")
                ->orWhere('no_payment_required_authority_reference', 'like', "%{$search}%")
                ->orWhereHas('program', fn (Builder $program): Builder => $program->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                ->orWhereHas('term', fn (Builder $term): Builder => $term
                    ->where('label', 'like', "%{$search}%")
                    ->orWhereHas('academicYear', fn (Builder $year): Builder => $year->where('label', 'like', "%{$search}%"))))])
            ->searchPlaceholder('Program or reference')
            ->filters([
                SelectFilter::make('term')
                    ->label('Term')
                    ->placeholder('All terms')
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Term::query()
                        ->with('academicYear')
                        ->orderByDesc('starts_on')
                        ->get()
                        ->mapWithKeys(fn (Term $term): array => [
                            $term->getKey() => collect([$term->academicYear?->label, $term->label])->filter()->implode(' · '),
                        ])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->where('term_id', (int) $data['value']),
                    )),
                SelectFilter::make('program')
                    ->label('Program')
                    ->placeholder('All programs')
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Program::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $query): Builder => $query->where('program_id', (int) $data['value']),
                    )),
                SelectFilter::make('state')
                    ->label('Status')
                    ->placeholder('All statuses')
                    ->native(false)
                    ->options([
                        FeePlan::StateDraft => 'Draft',
                        FeePlan::StatePublished => 'Current plan',
                        FeePlan::StateSuperseded => 'Superseded plan',
                    ]),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label('Filters')->color('gray')->labeledFrom(null))
            ->recordUrl(null)->recordAction(null)->recordActions([
                Action::make('openFeePlan')->button()->label(fn (FeePlan $record): string => $record->state === FeePlan::StateDraft ? 'Continue draft' : 'Open fee plan')
                    ->icon(Heroicon::Eye)
                    ->url(fn (FeePlan $record): string => FeePlanResource::getUrl('view', ['record' => $record]))->color('gray'),
            ])
            ->emptyState(function (Table $table, ListFeePlans $livewire): View|Htmlable|null {
                if ($livewire->feePlanGateActive()) {
                    return new HtmlString('');
                }

                $hasFiltersOrSearch = filled($livewire->getTableSearch())
                    || collect(Arr::dot($livewire->tableFilters ?? []))->contains(fn (mixed $value): bool => filled($value));

                if ($hasFiltersOrSearch) {
                    return null;
                }

                return view('filament.components.accounting-table-empty-state', [
                    'table' => $table,
                    'heading' => 'No fee plans yet',
                    'description' => 'Create a draft for an approved program and term. Published plans provide the source for student assessments.',
                ]);
            })
            ->emptyStateHeading('No matching fee plans')
            ->emptyStateDescription('Adjust the search or filters, or create a draft for an approved program and term.')
            ->emptyStateActions([
                Action::make('clearEmptyStateFilters')
                    ->label(fn (ListFeePlans $livewire): string => filled($livewire->getTableSearch()) && ! collect(Arr::dot($livewire->tableFilters ?? []))->contains(fn (mixed $value): bool => filled($value))
                        ? 'Clear search'
                        : 'Clear filters')
                    ->color('gray')
                    ->visible(fn (ListFeePlans $livewire): bool => ! $livewire->feePlanGateActive()
                        && (filled($livewire->getTableSearch())
                            || collect(Arr::dot($livewire->tableFilters ?? []))->contains(fn (mixed $value): bool => filled($value))))
                    ->action(fn (ListFeePlans $livewire) => $livewire->clearFeePlanFilters()),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw("CASE fee_plans.state WHEN 'Published' THEN 0 WHEN 'Draft' THEN 1 ELSE 2 END")
                ->orderBy(Term::query()->select('starts_on')->whereColumn('terms.id', 'fee_plans.term_id'))
                ->orderBy('program_id')->orderByDesc('version')->orderBy('id'));
    }

    public static function draftReadiness(FeePlan $plan): ?string
    {
        return $plan->state === FeePlan::StateDraft ? app(PublishFeePlan::class)->readiness($plan) : null;
    }
}
