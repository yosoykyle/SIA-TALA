<?php

namespace App\Filament\Student\Pages;

use App\Actions\Enrollment\CurrentOfficialEnrollmentResolver;
use App\Actions\StudentHub\RecordStudentScheduleAccess;
use App\Models\CourseComponent;
use App\Models\Enrollment;
use App\Models\SectionMeeting;
use App\Models\StudentScheduleBinding;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class ScheduleView extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Class Schedule';

    protected static ?string $title = 'Class Schedule';

    protected string $view = 'filament.student.pages.generic-table';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            app(RecordStudentScheduleAccess::class)->execute($user, request());
        }
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('printSchedule')
                ->label('Print / Save as PDF')
                ->icon('heroicon-o-printer')
                ->labeledFrom('sm')
                ->tooltip('Print or save the current class schedule as PDF')
                ->url(route('student.schedule.print'))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->hasCurrentOfficialSchedule()),
        ];
    }

    public function table(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();
        $enrollment = app(CurrentOfficialEnrollmentResolver::class)->forStudent($user);

        return $table
            ->query(
                StudentScheduleBinding::query()
                    ->activeOfficial()
                    ->when(
                        $enrollment instanceof Enrollment,
                        fn ($query) => $query->forEnrollment($enrollment),
                        fn ($query) => $query->whereRaw('1 = 0'),
                    )
                    ->with([
                        'courseEnrollment.termOffering.term',
                        'courseEnrollment.termOffering.curriculumEntry.courseSpecification.course',
                        'sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
                        'sectionMeeting.schedulingDemand.courseComponent',
                        'sectionMeeting.faculty',
                        'sectionMeeting.room',
                    ])
            )
            ->columns([
                TextColumn::make('courseEnrollment.termOffering.term.label')
                    ->label('Term'),
                TextColumn::make('courseEnrollment.termOffering.curriculumEntry.courseSpecification.course.code')
                    ->label('Course'),
                TextColumn::make('courseEnrollment.termOffering.curriculumEntry.courseSpecification.title')
                    ->label('Description')
                    ->wrap(),
                TextColumn::make('sectionMeeting.schedulingDemand.sectionDeliveryGroup.section.code')
                    ->label('Section'),
                TextColumn::make('sectionMeeting.schedulingDemand.courseComponent.component_type')
                    ->label('Component')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CourseComponent::typeOptions()[$state] ?? str((string) $state)->headline()->toString()),
                TextColumn::make('courseEnrollment.units_snapshot')
                    ->label('Units'),
                TextColumn::make('sectionMeeting.faculty.name')
                    ->label('Faculty'),
                TextColumn::make('meeting_time')
                    ->label('Time')
                    ->state(function (StudentScheduleBinding $record): string {
                        $meeting = $record->sectionMeeting;

                        return $meeting instanceof SectionMeeting ? self::timeRange($meeting) : 'Unscheduled';
                    }),
                TextColumn::make('sectionMeeting.room.code')
                    ->label('Room')
                    ->placeholder('TBA'),
                TextColumn::make('sectionMeeting.modality')
                    ->label('Modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => SectionMeeting::modalityOptions()[$state] ?? str((string) $state)->headline()->toString()),
            ])
            ->groups([
                Group::make('sectionMeeting.day_of_week')
                    ->label('Day')
                    ->getTitleFromRecordUsing(function (StudentScheduleBinding $record): string {
                        $meeting = $record->sectionMeeting;

                        return $meeting instanceof SectionMeeting
                            ? SectionMeeting::dayOptions()[(int) $meeting->day_of_week] ?? 'Unscheduled'
                            : 'Unscheduled';
                    }),
            ])
            ->defaultGroup('sectionMeeting.day_of_week')
            ->groupingSettingsHidden()
            ->stackedOnMobile()
            ->emptyStateHeading('No schedule available')
            ->emptyStateDescription($enrollment instanceof Enrollment
                ? 'Your official enrollment is active, but no published class meetings are assigned yet. Please contact the Registrar Office.'
                : 'No official enrollment exists for the active term yet. Complete enrollment or contact the Registrar Office.')
            ->emptyStateIcon('heroicon-o-calendar');
    }

    private static function timeRange(SectionMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode(' - ');
    }

    private function hasCurrentOfficialSchedule(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $enrollment = app(CurrentOfficialEnrollmentResolver::class)->forStudent($user);

        return $enrollment instanceof Enrollment
            && StudentScheduleBinding::query()
                ->activeOfficial()
                ->forEnrollment($enrollment)
                ->exists();
    }
}
