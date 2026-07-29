<?php

namespace App\Filament\Resources\ApplicantIntakes\Pages;

use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use App\Models\ApplicantIntake;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListApplicantIntakes extends ListRecords
{
    protected static string $resource = ApplicantIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'needs_registrar_action' => Tab::make('Needs Registrar Action')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', [
                        ApplicantIntake::StatusPending,
                        ApplicantIntake::StatusForEvaluation,
                    ])
                    ->whereNull('handed_over_at')),
            'waiting_on_applicant' => Tab::make('Waiting on Applicant')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', ApplicantIntake::StatusActionRequired)
                    ->whereNull('handed_over_at')),
            'ready_for_handover' => Tab::make('Approved / Handover Review')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', ApplicantIntake::StatusApproved)
                    ->whereNull('handed_over_at')),
            'completed_history' => Tab::make('Completed / History')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where(fn (Builder $query): Builder => $query
                        ->whereNotNull('handed_over_at')
                        ->orWhere('status', ApplicantIntake::StatusWithdrawn))),
        ];
    }
}
