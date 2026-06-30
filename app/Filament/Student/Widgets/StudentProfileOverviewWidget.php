<?php

namespace App\Filament\Student\Widgets;

use App\Actions\Enrollment\AcademicProgressionService;
use App\Actions\Finance\FinanceEvidenceService;
use App\Models\StudentProfile;
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

        $finance = app(FinanceEvidenceService::class)->studentFinance($user);
        $balance = $finance['state']['ledger_balance'] ?? 'PHP 0.00';
        $hasBalance = ($finance['summary']['balance'] ?? '0.00') !== '0.00';
        $progression = app(AcademicProgressionService::class)->evaluate($profile);

        $stats = [
            Stat::make('Lifecycle Status', str((string) $profile->lifecycle_status)->headline()->toString())
                ->description('Current student profile status')
                ->color(match ($profile->lifecycle_status) {
                    StudentProfile::LifecycleActive => 'success',
                    StudentProfile::LifecycleArchived => 'danger',
                    default => 'info',
                }),
            Stat::make('Academic Standing', str((string) $profile->academic_standing)->headline()->toString())
                ->description('Recommended: '.$progression['standing'].'; blockers: '.count($progression['blockers']))
                ->color('info'),
            Stat::make('Balance', $balance)
                ->description('Ledger-derived outstanding balance')
                ->color($hasBalance ? 'warning' : 'success'),
        ];

        foreach (array_slice($progression['blockers'], 0, 3) as $blocker) {
            $stats[] = Stat::make(
                'Progression Blocker',
                $blocker['course_code'] ?? 'Curriculum requirement',
            )->description(collect([
                $blocker['rule'] ?? $blocker['reason'] ?? null,
                filled($blocker['source']['id'] ?? null) ? 'Source #'.$blocker['source']['id'] : null,
            ])->filter()->implode(' - '))->color('warning');
        }

        return $stats;
    }
}
