<?php

namespace App\Filament\Resources\ScheduleGenerationRuns\RelationManagers;

use App\Filament\Resources\ScheduleGenerationRuns\Schemas\ScheduleRevisionEventInfolist;
use App\Models\Course;
use App\Models\ScheduleGenerationRun;
use App\Models\ScheduleRevisionEvent;
use App\Models\Section;
use App\Models\SectionMeeting;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class RevisionEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisionEvents';

    protected static ?string $title = 'Revision History';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = auth()->user();

        return $ownerRecord instanceof ScheduleGenerationRun
            && $actor instanceof User
            && Gate::forUser($actor)->allows('viewAny', SectionMeeting::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function infolist(Schema $schema): Schema
    {
        return ScheduleRevisionEventInfolist::configure($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn ($query) => $query->with([
                'changedBy',
                'sectionMeeting.schedulingDemand.courseComponent.courseSpecification.course',
                'sectionMeeting.schedulingDemand.sectionDeliveryGroup.section',
            ]))
            ->columns([
                TextColumn::make('change_type')
                    ->label('Change Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ScheduleRevisionEvent::changeTypeOptions()[$state] ?? str($state)->headline()->toString())
                    ->sortable(),
                TextColumn::make('section_code')
                    ->label('Section')
                    ->state(function (ScheduleRevisionEvent $record): string {
                        $section = self::meeting($record)?->schedulingDemand?->sectionDeliveryGroup?->section;

                        return $section instanceof Section ? $section->code : '-';
                    }),
                TextColumn::make('course_code')
                    ->label('Course')
                    ->state(function (ScheduleRevisionEvent $record): string {
                        $course = self::meeting($record)?->schedulingDemand?->termOffering?->course();

                        return $course instanceof Course ? $course->code : '-';
                    }),
                TextColumn::make('meeting_sequence')
                    ->label('Meeting')
                    ->state(function (ScheduleRevisionEvent $record): int {
                        $meeting = self::meeting($record);

                        return $meeting instanceof SectionMeeting ? (int) $meeting->meeting_sequence : 0;
                    })
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? 'Meeting '.$state : '-'),
                TextColumn::make('effective_date')
                    ->label('Effective Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('actor_name')
                    ->label('Changed By')
                    ->state(fn (ScheduleRevisionEvent $record): string => self::actorLabel($record)),
                TextColumn::make('reason')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('affected_student_count')
                    ->label('Students')
                    ->numeric(),
                TextColumn::make('affected_faculty_count')
                    ->label('Faculty')
                    ->numeric(),
                TextColumn::make('created_at')
                    ->label('Recorded At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('change_type')
                    ->label('Change Type')
                    ->options(ScheduleRevisionEvent::changeTypeOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->label('Details'),
            ])
            ->toolbarActions([]);
    }

    private static function meeting(ScheduleRevisionEvent $record): ?SectionMeeting
    {
        $meeting = $record->getRelationValue('sectionMeeting');

        return $meeting instanceof SectionMeeting ? $meeting : null;
    }

    private static function actorLabel(ScheduleRevisionEvent $record): string
    {
        $actor = $record->getRelationValue('changedBy');

        return $actor instanceof User ? $actor->name : 'User #'.(int) $record->changed_by;
    }
}
