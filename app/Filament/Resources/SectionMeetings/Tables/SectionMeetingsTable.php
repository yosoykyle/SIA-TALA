<?php

namespace App\Filament\Resources\SectionMeetings\Tables;

use App\Models\SectionMeeting;
use App\Models\User;
use Filament\Actions\EditAction;
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
                $query->with(['term', 'section', 'subject', 'faculty', 'committer']);

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
                    ->formatStateUsing(fn (?int $state): string => match ($state) {
                        1 => 'Monday',
                        2 => 'Tuesday',
                        3 => 'Wednesday',
                        4 => 'Thursday',
                        5 => 'Friday',
                        6 => 'Saturday',
                        7 => 'Sunday',
                        default => '-',
                    }),
                TextColumn::make('starts_at')
                    ->label('Start'),
                TextColumn::make('ends_at')
                    ->label('End'),
                TextColumn::make('modality')
                    ->badge(),
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
                    ->options([
                        'on_site' => 'On-site',
                        'online' => 'Online',
                        'modular' => 'Modular',
                        'blended' => 'Blended',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (SectionMeeting $record): bool => auth()->user()?->can('manage-schedules') ?? false),
            ])
            ->toolbarActions([]);
    }
}
