<?php

namespace App\Filament\Resources\FeePlans\Tables;

use App\Actions\Finance\PublishFeePlan;
use App\Models\FeePlan;
use App\Models\Term;
use Filament\Tables\Columns\Layout\View as RecordView;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeePlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => 'accounting-records-table fee-plans-table'])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['program', 'term', 'charges', 'obligations'])
                ->withSum('charges', 'amount')->withSum(['obligations as enrollment_payment_amount' => fn (Builder $query): Builder => $query->where('required_for_enrollment', true)], 'amount'))
            ->columns([
                RecordView::make('filament.admin.fee-plans.record-row'),
            ])
            ->searchable([fn (Builder $query, string $search): Builder => $query->where(fn (Builder $scope): Builder => $scope
                ->where('authority_reference', 'like', "%{$search}%")
                ->orWhereHas('program', fn (Builder $program): Builder => $program->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                ->orWhereHas('term', fn (Builder $term): Builder => $term->where('label', 'like', "%{$search}%")))])
            ->searchPlaceholder('Program, term or approval reference')
            ->filters([
                SelectFilter::make('term')->relationship('term', 'label')->searchable()->preload(),
                SelectFilter::make('program')->relationship('program', 'name')->searchable()->preload(),
                SelectFilter::make('state')->options(['Draft' => 'Draft', 'Published' => 'Published', 'Superseded' => 'Superseded']),
            ])
            ->recordUrl(null)->recordAction(null)->recordActions([])
            ->emptyStateHeading('No matching Fee Plans')
            ->emptyStateDescription('Adjust the search or filters, or create a draft for an approved program and term.')
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
