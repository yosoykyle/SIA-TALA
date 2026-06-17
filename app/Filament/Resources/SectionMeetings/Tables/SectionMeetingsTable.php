<?php

namespace App\Filament\Resources\SectionMeetings\Tables;

use App\Models\SectionMeeting;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SectionMeetingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $query->with(['term', 'section', 'sectionDeliveryGroup', 'subject', 'faculty', 'committer']);

                $user = auth()->user();

                if ($user instanceof User
                    && $user->hasRole('faculty')
                    && ! $user->can('manage-schedules')
                    && ! $user->can('view-global-records')) {
                    $query->where(function ($facultyQuery) use ($user): void {
                        $facultyQuery
                            ->where('faculty_id', $user->id)
                            ->orWhereExists(function ($sectionTeacherQuery) use ($user): void {
                                $sectionTeacherQuery
                                    ->selectRaw('1')
                                    ->from('section_teacher')
                                    ->whereColumn('section_teacher.section_id', 'section_meetings.section_id')
                                    ->whereColumn('section_teacher.subject_id', 'section_meetings.subject_id')
                                    ->where('section_teacher.user_id', $user->id);
                            });
                    });
                }

                return $query;
            })
            ->columns([
                TextColumn::make('term.term_name')
                    ->label('Term')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('section.name')
                    ->label('Section')
                    ->searchable(),
                TextColumn::make('sectionDeliveryGroup.name')
                    ->label('Delivery Group')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('subject.code')
                    ->label('Subject')
                    ->searchable(),
                TextColumn::make('faculty.name')
                    ->label('Faculty')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('room')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('day_of_week')
                    ->label('Day')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : (SectionMeeting::dayOptions()[$state] ?? '-')),
                TextColumn::make('starts_at')
                    ->label('Start'),
                TextColumn::make('ends_at')
                    ->label('End'),
                TextColumn::make('modality')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '-' : (SectionMeeting::modalityOptions()[$state] ?? str($state)->replace('_', ' ')->headline()->toString())),
                TextColumn::make('committer.name')
                    ->label('Committed By')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('committed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('modality')
                    ->options(SectionMeeting::modalityOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
