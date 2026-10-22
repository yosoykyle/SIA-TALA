<?php

namespace App\Filament\Resources\FeePlans\Schemas;

use App\Actions\Finance\PublishFeePlan;
use App\Models\FeePlan;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class FeePlanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.fee-plans.detail')->viewData(fn (FeePlan $record): array => [
                'plan' => $record->loadMissing(['program', 'term', 'charges', 'obligations']),
                'readiness' => $record->state === FeePlan::StateDraft ? app(PublishFeePlan::class)->readiness($record) : null,
                'versions' => FeePlan::query()->where('program_id', $record->program_id)->where('term_id', $record->term_id)->orderByDesc('version')->get(),
            ])->columnSpanFull(),
        ]);
    }
}
