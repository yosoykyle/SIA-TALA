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
            Stat::make('Official Academic Standing', AcademicProgressionService::standingLabel($profile->academic_standing))
                ->description($this->academicStandingGuidance(
                    (string) $profile->academic_standing,
                    $progression['recommendation'],
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
            )->description(
                AcademicProgressionService::blockerMessage($blocker)
                .' Contact the Registrar Office; it will route Academic Head review when required.',
            )->color('warning');
        }

        return $stats;
    }

    /**
     * @param  array{available: bool, standing: ?string, label: string, explanation: string}  $recommendation
     */
    private function academicStandingGuidance(
        string $officialStanding,
        array $recommendation,
        int $blockerCount,
    ): string {
        if (! $recommendation['available']) {
            return $recommendation['label'].'. '.$recommendation['explanation'].' Contact the Registrar Office if review is needed.';
        }

        if (filled($recommendation['standing']) && $recommendation['standing'] !== $officialStanding) {
            return 'System review suggests '.AcademicProgressionService::standingLabel($recommendation['standing']).'. Registrar Office must confirm any change.';
        }

        if ($blockerCount > 0) {
            return "Review {$blockerCount} academic requirement(s) with the Registrar Office.";
        }

        return 'System review currently supports this standing. The Registrar-recorded value remains official.';
    }
}
