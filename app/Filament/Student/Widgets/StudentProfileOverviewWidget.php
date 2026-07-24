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
                ->description(match ($profile->lifecycle_status) {
                    StudentProfile::LifecycleArchived => 'This is your official Student Profile status. Contact the Registrar Office if this is unexpected.',
                    default => 'This is your official Student Profile status.',
                })
                ->color(match ($profile->lifecycle_status) {
                    StudentProfile::LifecycleActive => 'success',
                    StudentProfile::LifecycleArchived => 'danger',
                    default => 'info',
                }),
            Stat::make('Academic Standing', str((string) $profile->academic_standing)->headline()->toString())
                ->description($this->academicStandingGuidance(
                    (string) $profile->academic_standing,
                    (string) $progression['standing'],
                    count($progression['blockers']),
                ))
                ->color('info'),
            Stat::make('Balance', $balance)
                ->description($hasBalance
                    ? 'An outstanding balance is posted. Open Finance or contact the Accounting Office.'
                    : 'No outstanding posted balance.')
                ->color($hasBalance ? 'warning' : 'success'),
        ];

        foreach (array_slice($progression['blockers'], 0, 3) as $blocker) {
            $stats[] = Stat::make(
                'Academic action needed',
                $blocker['course_code'] ?? 'Curriculum requirement',
            )->description(collect([
                $blocker['rule'] ?? $blocker['reason'] ?? null,
                'Contact the Registrar Office for guidance.',
            ])->filter()->implode(' '))->color('warning');
        }

        return $stats;
    }

    private function academicStandingGuidance(
        string $officialStanding,
        string $recommendedStanding,
        int $blockerCount,
    ): string {
        if ($recommendedStanding !== '' && $recommendedStanding !== $officialStanding) {
            return 'System review suggests '.str($recommendedStanding)->headline()->toString().'. Registrar Office must confirm any change.';
        }

        if ($blockerCount > 0) {
            return "Review {$blockerCount} academic requirement(s) with the Registrar Office.";
        }

        return 'No academic progression issue is currently recorded.';
    }
}
