<?php

namespace App\Filament\Student\Widgets;

use App\Actions\Academics\AcademicEnrollmentEffect;
use App\Actions\Academics\CurriculumEvaluation;
use App\Actions\Finance\FinanceEvidenceService;
use App\Models\AcademicDecision;
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
        $academicEffect = app(AcademicEnrollmentEffect::class)->forStudent($profile);
        $curriculum = app(CurriculumEvaluation::class)->forStudent($profile);

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
            Stat::make('Enrollment Guidance', str($academicEffect['effect'])->headline()->toString())
                ->description($academicEffect['reason'].' Source: '.$academicEffect['source'].'.')
                ->color(match ($academicEffect['effect']) {
                    AcademicDecision::EffectBlocked => 'danger',
                    AcademicDecision::EffectAdvisingRequired, AcademicDecision::EffectPendingDecision => 'warning',
                    default => 'success',
                }),
            Stat::make('Curriculum Requirements', $curriculum['deficiency_count'].' remaining')
                ->description($curriculum['completed_units'].' earned curriculum units are currently projected from released results.')
                ->color($curriculum['deficiency_count'] > 0 ? 'info' : 'success'),
            Stat::make('Balance', $balance)
                ->description($hasBalance
                    ? 'An outstanding balance is posted. Open Finance or contact the Accounting Office.'
                    : 'No outstanding posted balance.')
                ->color($hasBalance ? 'warning' : 'success'),
        ];

        return $stats;
    }
}
