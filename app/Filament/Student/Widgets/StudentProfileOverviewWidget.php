<?php

namespace App\Filament\Student\Widgets;

use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StudentProfileOverviewWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        // Load profile
        $user->loadMissing('studentProfile');
        $profile = $user->studentProfile;

        if (! $profile) {
            return [
                Stat::make('Status', 'No Profile Found')
                    ->description('Please contact the registrar.')
                    ->color('danger'),
            ];
        }

        return [
            Stat::make('Official Status', str((string) $profile->operational_status)->title()->toString())
                ->description('Current Academic Status')
                ->color(match ($profile->operational_status) {
                    'enrolled' => 'success',
                    'probationary', 'irregular' => 'warning',
                    'dropped', 'LOA', 'AWOL' => 'danger',
                    default => 'info',
                }),
            Stat::make('Modality', str((string) $profile->modality)->title()->toString())
                ->description('Enrolled Modality')
                ->color('info'),
            Stat::make('Balance', 'PHP '.number_format((float) $profile->current_balance, 2))
                ->description('Outstanding Balance')
                ->color($profile->current_balance > 0 ? 'warning' : 'success'),
        ];
    }
}
