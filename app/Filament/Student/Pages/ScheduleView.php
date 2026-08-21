<?php

namespace App\Filament\Student\Pages;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\RecordStudentScheduleAccess;
use App\Actions\StudentHub\StudentDashboardService;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Pages\Page;

class ScheduleView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Class Schedule';

    protected static ?string $title = 'Class Schedule';

    protected string $view = 'filament.student.pages.schedule-view';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            app(RecordStudentScheduleAccess::class)->execute($user, request());
        }
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('printSchedule')
                ->label('Print / Save as PDF')
                ->icon('heroicon-o-printer')
                ->url(route('student.schedule.print'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->scheduleRows() !== []),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['scheduleRows' => $this->scheduleRows()];
    }

    /** @return list<array<string, mixed>> */
    private function scheduleRows(): array
    {
        $user = auth()->user();
        if (! $user instanceof User
            || ! app(CurrentOfficialEnrollmentResolver::class)->forStudent($user) instanceof Enrollment) {
            return [];
        }

        $profile = StudentProfile::query()->where('user_id', $user->id)->first();

        return $profile instanceof StudentProfile
            ? app(StudentDashboardService::class)->forStudent($profile)['schedule']['current']
            : [];
    }
}
