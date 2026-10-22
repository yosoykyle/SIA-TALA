<?php

namespace App\Filament\Student\Pages;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\RecordStudentScheduleAccess;
use App\Actions\StudentHub\StudentDashboardService;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\RegistrationSchedulePresentation;
use Filament\Actions\Action;
use Filament\Pages\Page;

class ScheduleView extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Class schedule';

    protected static ?string $title = 'Class schedule';

    protected ?string $subheading = 'Your registered classes and published meeting times, all in Asia/Manila.';

    protected string $view = 'filament.student.pages.schedule-view';

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(panel: 'student') => 'Overview', $this->getTitle()];
    }

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
                ->label('Print schedule')
                ->icon('heroicon-o-printer')
                ->url(route('student.schedule.print'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->scheduleRows() !== []),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $actor = auth()->user();

        return [
            'scheduleRows' => $this->scheduleRows(),
            'schedulePresentation' => $actor instanceof User ? app(RegistrationSchedulePresentation::class)->currentFor($actor) : null,
            'hasOfficialEnrollment' => $actor instanceof User && app(CurrentOfficialEnrollmentResolver::class)->forStudent($actor) instanceof Enrollment,
        ];
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
