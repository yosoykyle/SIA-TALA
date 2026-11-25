<?php

namespace App\Filament\Resources\StudentProfiles;

use App\Actions\Academics\AcademicRecordNotificationService;
use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Academics\RecordAcademicDecision;
use App\Models\AcademicDecision;
use App\Models\StudentProfile;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Registrar Irregular review: confirm the flag or keep the Student regular with a reason (#99 Scope 11).
 *
 * Both actions record an {@see AcademicDecision}; "Irregular" stays a display label over Individually Advised.
 */
class IrregularReviewActions
{
    /** @param Closure(): StudentProfile|StudentProfile|null $student */
    public static function confirm(Closure|StudentProfile|null $student = null): Action
    {
        return Action::make('confirmIrregular')
            ->label('Confirm irregular')
            ->icon('heroicon-o-check-badge')
            ->color('warning')
            ->visible(fn (?StudentProfile $record = null): bool => self::canReview(self::resolve($student, $record)))
            ->authorize(fn (): bool => self::isRegistrar())
            ->modalHeading('Confirm irregular status')
            ->modalDescription('The student will enroll through Individually Advised and will be told why by email and on their dashboard.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalContent(fn (?StudentProfile $record = null): View => view('filament.admin.student-profiles.irregular-reasons', [
                'review' => app(IrregularStudentReasons::class)->forStudent(self::resolve($student, $record)),
            ]))
            ->schema([
                Textarea::make('remarks')
                    ->label('Registrar remarks (optional)')
                    ->helperText('Shown to the student with the reason.')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Confirm irregular')
            ->action(function (array $data, ?StudentProfile $record = null) use ($student): void {
                $profile = self::resolve($student, $record);
                $review = app(IrregularStudentReasons::class)->forStudent($profile);
                $primary = $review['primary'];
                $reason = 'Confirmed irregular – '.($primary['label'] ?? 'Registrar review').'. '.($primary['details'] ?? '')
                    .(filled($data['remarks'] ?? null) ? ' Registrar remarks: '.trim((string) $data['remarks']) : '');

                self::record($profile, AcademicDecision::EffectAdvisingRequired, $reason, StudentProfile::StandingIrregular, $review['status_label']);
            });
    }

    /** @param Closure(): StudentProfile|StudentProfile|null $student */
    public static function keepRegular(Closure|StudentProfile|null $student = null): Action
    {
        return Action::make('keepRegular')
            ->label('Keep regular')
            ->icon('heroicon-o-hand-raised')
            ->color('gray')
            ->visible(fn (?StudentProfile $record = null): bool => self::canReview(self::resolve($student, $record)))
            ->authorize(fn (): bool => self::isRegistrar())
            ->modalHeading('Keep this student regular')
            ->modalDescription('Use this only while the reason is not final, for example a grade appeal that is still pending.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalContent(fn (?StudentProfile $record = null): View => view('filament.admin.student-profiles.irregular-reasons', [
                'review' => app(IrregularStudentReasons::class)->forStudent(self::resolve($student, $record)),
            ]))
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Shown to the student.')
                    ->placeholder('Grade appeal for IT 201 is still pending.')
                    ->required()
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->modalSubmitActionLabel('Keep regular')
            ->action(fn (array $data, ?StudentProfile $record = null) => self::record(
                self::resolve($student, $record),
                AcademicDecision::EffectAllowed,
                'Kept regular: '.trim((string) $data['reason']),
                null,
                null,
            ));
    }

    private static function record(StudentProfile $student, string $effect, string $reason, ?string $standing, ?string $statusLabel): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && self::isRegistrar(), 403);

        try {
            $decision = app(RecordAcademicDecision::class)->execute(
                $student,
                null,
                $effect,
                null,
                null,
                $reason,
                Carbon::today(config('app.display_timezone')),
                null,
                $actor,
                $standing,
            );
        } catch (RuntimeException $exception) {
            Notification::make()->title('Decision was not recorded')->body($exception->getMessage())->danger()->send();

            throw new Halt;
        }

        if ($statusLabel !== null) {
            app(AcademicRecordNotificationService::class)->recordIrregularConfirmedAfterCommit($decision, $statusLabel);
        }

        Notification::make()
            ->title($statusLabel !== null ? 'Irregular status confirmed' : 'Student kept regular')
            ->success()
            ->send();
    }

    private static function canReview(StudentProfile $student): bool
    {
        return self::isRegistrar() && app(IrregularStudentReasons::class)->needsReview($student);
    }

    private static function isRegistrar(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->status === User::StatusActive && $user->hasRole(User::StaffRoleRegistrar);
    }

    /** @param Closure(): StudentProfile|StudentProfile|null $student */
    private static function resolve(Closure|StudentProfile|null $student, ?StudentProfile $record): StudentProfile
    {
        $resolved = $student instanceof Closure ? $student() : ($student ?? $record);
        abort_unless($resolved instanceof StudentProfile, 404);

        return $resolved;
    }
}
