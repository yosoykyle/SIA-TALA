<?php

namespace App\Filament\Student\Widgets;

use App\Actions\StudentHub\StudentHubPriorityResolver;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StudentPriorityNoticeWidget extends BaseWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return self::resolveNotice() !== null;
    }

    protected function getStats(): array
    {
        $notice = self::resolveNotice();

        if ($notice === null) {
            return [];
        }

        $stat = Stat::make($notice['tier'], $notice['student_reason'])
            ->color(self::colorForTier($notice['tier']));

        $description = collect([
            $notice['required_action'],
            filled($notice['office_to_contact']) ? 'Office to contact: '.$notice['office_to_contact'] : null,
        ])->filter()->implode(' ');

        if (filled($description)) {
            $stat = $stat->description($description);
        }

        return [$stat];
    }

    /**
     * @return array{tier:string, student_reason:string, required_action:?string, office_to_contact:?string}|null
     */
    private static function resolveNotice(): ?array
    {
        /** @var User $user */
        $user = auth()->user();

        $user->loadMissing('studentProfile');
        $profile = $user->studentProfile;

        if ($profile === null) {
            return null;
        }

        return app(StudentHubPriorityResolver::class)->resolve($profile);
    }

    private static function colorForTier(string $tier): string
    {
        return match ($tier) {
            'Enrollment Blocked', 'COR Blocked' => 'danger',
            'Payment Pending or Rejected', 'Security / Account Notice' => 'warning',
            default => 'info',
        };
    }
}
