<?php

namespace App\Filament\Resources\AccountingAdjustments\Pages;

use App\Actions\Finance\AccountingAdjustmentService;
use App\Filament\Resources\AccountingAdjustments\AccountingAdjustmentResource;
use App\Models\AccountingAdjustment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAccountingAdjustment extends CreateRecord
{
    protected static string $resource = AccountingAdjustmentResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        $postedAt = filled($data['posted_at'] ?? null)
            ? CarbonImmutable::parse((string) $data['posted_at'], config('app.timezone'))
            : null;

        $summary = app(AccountingAdjustmentService::class)->post($data, $actor, $postedAt);

        return AccountingAdjustment::query()->findOrFail($summary['adjustment_id']);
    }
}
