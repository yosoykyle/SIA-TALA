<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\FacultyQualifications\FacultyQualificationResource;
use App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource;
use App\Filament\Resources\SchedulingDemands\SchedulingDemandResource;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Filament\Resources\Terms\TermResource;
use App\Models\CurriculumVersion;
use App\Models\FacultyQualification;
use App\Models\Program;
use App\Models\Room;
use App\Models\SchedulingDemand;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\Term;
use App\Models\TermOffering;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RegistrarOperationalReadinessWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Registrar Operating Order';

    protected ?string $description = 'Follow steps 1–6. Each card opens the authoritative records for that stage.';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(User::StaffRoleRegistrar);
    }

    protected function getStats(): array
    {
        $term = Term::query()
            ->with('academicYear')
            ->where('state', Term::StateActive)
            ->orderByDesc('starts_on')
            ->first();

        if (! $term instanceof Term) {
            return $this->missingPeriodStats();
        }

        $activePrograms = Program::query()->where('is_active', true)->count();
        $activeCurricula = CurriculumVersion::query()
            ->where('state', CurriculumVersion::StateActive)
            ->whereHas('program', fn ($query) => $query->where('is_active', true))
            ->whereHas('entries')
            ->count();
        $offeringCount = TermOffering::query()->whereBelongsTo($term)->count();
        $sectionCount = Section::query()
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->count();
        $facultyCount = FacultyQualification::query()
            ->active()
            ->distinct()
            ->count('faculty_user_id');
        $roomCount = Room::query()->where('is_active', true)->count();
        $readyDemandCount = SchedulingDemand::query()
            ->where('validation_state', SchedulingDemand::ValidationReadyForReview)
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->count();
        $actionRequiredDemandCount = SchedulingDemand::query()
            ->where('validation_state', SchedulingDemand::ValidationActionRequired)
            ->whereHas('termOffering', fn ($query) => $query->whereBelongsTo($term))
            ->count();
        $publishedMeetingCount = SectionMeeting::query()
            ->activeOfficial()
            ->whereHas(
                'schedulingDemand.termOffering',
                fn ($query) => $query->whereBelongsTo($term),
            )
            ->count();

        return [
            Stat::make(
                '1. Academic Period',
                collect([$term->academicYear?->label, $term->label])->filter()->implode(' / '),
            )
                ->description('Start here: confirm term dates, state, calendar, and operating hours.')
                ->descriptionIcon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('success')
                ->url(TermResource::getUrl('index')),
            Stat::make(
                '2. Active Curricula',
                "{$activeCurricula} ".str('program')->plural($activeCurricula).' ready',
            )
                ->description("Confirm all {$activePrograms} active programs have the correct three-year curriculum.")
                ->descriptionIcon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color($activePrograms > 0 && $activeCurricula === $activePrograms ? 'success' : 'warning')
                ->url(CurriculumVersionResource::getUrl('index')),
            Stat::make(
                '3. Offerings & Sections',
                "{$offeringCount} offerings / {$sectionCount} sections",
            )
                ->description('Build term offerings first, then confirm their section-planning records.')
                ->descriptionIcon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color($offeringCount > 0 && $sectionCount >= $offeringCount ? 'success' : 'warning')
                ->url(TermOfferingResource::getUrl('index')),
            Stat::make(
                '4. Teaching Resources',
                "{$facultyCount} faculty / {$roomCount} rooms",
            )
                ->description('Confirm qualifications, load limits, rooms, and unavailability before demand review.')
                ->descriptionIcon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color($facultyCount > 0 && $roomCount > 0 ? 'success' : 'warning')
                ->url(FacultyQualificationResource::getUrl('index')),
            Stat::make(
                '5. Scheduling Demands',
                $actionRequiredDemandCount > 0
                    ? "{$readyDemandCount} ready / {$actionRequiredDemandCount} need action"
                    : "{$readyDemandCount} ready for review",
            )
                ->description('Resolve every readiness finding before starting a solver run.')
                ->descriptionIcon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color($readyDemandCount > 0 && $actionRequiredDemandCount === 0 ? 'success' : 'warning')
                ->url(SchedulingDemandResource::getUrl('index')),
            Stat::make(
                '6. Published Timetable',
                $publishedMeetingCount > 0
                    ? "{$publishedMeetingCount} official ".str('meeting')->plural($publishedMeetingCount)
                    : 'Not published',
            )
                ->description($publishedMeetingCount > 0
                    ? 'The official timetable is available to authorized staff, faculty, and students.'
                    : 'Review a solver candidate and publish it before enrollment schedule projection.')
                ->descriptionIcon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color($publishedMeetingCount > 0 ? 'success' : 'gray')
                ->url(ScheduleGenerationRunResource::getUrl('index')),
        ];
    }

    /**
     * @return list<Stat>
     */
    private function missingPeriodStats(): array
    {
        return [
            Stat::make('1. Academic Period', 'Needs setup')
                ->description('Create and activate the academic year and term before continuing.')
                ->descriptionIcon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('warning')
                ->url(TermResource::getUrl('index')),
            Stat::make('2–6. Later stages', 'Blocked')
                ->description('Curricula, offerings, resources, demands, and publication depend on an active term.')
                ->color('gray'),
        ];
    }
}
