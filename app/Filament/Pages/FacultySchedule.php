<?php

namespace App\Filament\Pages;

use App\Models\CourseComponent;
use App\Models\SectionMeeting;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FacultySchedule extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Faculty';

    protected static ?string $navigationLabel = 'Assigned Schedule';

    protected static ?string $title = 'My Assigned Schedule';

    protected string $view = 'filament.student.pages.generic-table';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleFaculty) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->meetingsQuery())
            ->columns([
                TextColumn::make('schedulingDemand.termOffering.term.label')
                    ->label('Term'),
                TextColumn::make('schedulingDemand.termOffering.curriculumEntry.courseSpecification.course.code')
                    ->label('Course'),
                TextColumn::make('schedulingDemand.termOffering.curriculumEntry.courseSpecification.title')
                    ->label('Description')
                    ->wrap(),
                TextColumn::make('schedulingDemand.sectionDeliveryGroup.section.code')
                    ->label('Section'),
                TextColumn::make('schedulingDemand.courseComponent.component_type')
                    ->label('Component')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CourseComponent::typeOptions()[$state] ?? str((string) $state)->headline()->toString()),
                TextColumn::make('meeting_time')
                    ->label('Time')
                    ->state(fn (SectionMeeting $record): string => self::timeRange($record)),
                TextColumn::make('room.code')
                    ->label('Room')
                    ->placeholder('TBA'),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => SectionMeeting::modalityOptions()[$state] ?? str((string) $state)->headline()->toString()),
            ])
            ->groups([
                Group::make('day_of_week')
                    ->label('Day')
                    ->getTitleFromRecordUsing(fn (SectionMeeting $record): string => SectionMeeting::dayOptions()[(int) $record->day_of_week] ?? 'Unscheduled'),
            ])
            ->defaultGroup('day_of_week')
            ->groupingSettingsHidden()
            ->emptyStateHeading('No assigned schedule')
            ->emptyStateDescription('Your published class assignments appear here.')
            ->emptyStateIcon(Heroicon::OutlinedCalendar);
    }

    /**
     * @return Builder<SectionMeeting>
     */
    private function meetingsQuery(): Builder
    {
        return SectionMeeting::query()
            ->activeOfficial()
            ->with([
                'schedulingDemand.termOffering.term',
                'schedulingDemand.termOffering.curriculumEntry.courseSpecification.course',
                'schedulingDemand.sectionDeliveryGroup.section',
                'schedulingDemand.courseComponent',
                'room',
            ])
            ->where('faculty_user_id', auth()->id())
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('id');
    }

    private static function timeRange(SectionMeeting $meeting): string
    {
        return collect([$meeting->starts_at, $meeting->ends_at])
            ->map(fn (mixed $time): string => CarbonImmutable::createFromFormat('H:i:s', (string) $time)->format('g:i A'))
            ->implode(' - ');
    }
}
