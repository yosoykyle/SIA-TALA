<?php

namespace App\Filament\Resources\FacultyQualifications\Schemas;

use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\FacultyQualification;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rules\Unique;

class FacultyQualificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Qualification')
                    ->description('Record the approved teacher and course. Qualifications apply across Terms.')
                    ->schema([
                        Group::make([
                            Select::make('faculty_user_id')
                                ->label('Teacher')
                                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('course_id', $get('course_id')))
                                ->validationMessages(['unique' => 'A qualification already exists for this faculty member and course. Edit the existing record.'])
                                ->relationship(
                                    'faculty',
                                    'name',
                                    modifyQueryUsing: fn (Builder $query): Builder => $query
                                        ->whereHas('roles', fn (Builder $rolesQuery): Builder => $rolesQuery
                                            ->where('name', User::StaffRoleFaculty))
                                        ->orderBy('name'),
                                )
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('course_id')
                                ->label('Approved course')
                                ->relationship(
                                    'course',
                                    'code',
                                    modifyQueryUsing: fn (Builder $query): Builder => $query->with(['specifications' => fn (HasMany $revisions): HasMany => $revisions->where('state', CourseSpecification::StateActive)->latest('id')])->orderBy('code'),
                                )
                                ->getOptionLabelFromRecordUsing(fn (Course $record): string => self::courseLabel($record))
                                ->getSearchResultsUsing(fn (string $search): array => Course::query()
                                    ->with(['specifications' => fn (HasMany $revisions): HasMany => $revisions->where('state', CourseSpecification::StateActive)->latest('id')])
                                    ->where(fn (Builder $query): Builder => $query->where('code', 'like', '%'.$search.'%')
                                        ->orWhereHas('specifications', fn (Builder $revisions): Builder => $revisions->where('state', CourseSpecification::StateActive)->where('title', 'like', '%'.$search.'%')))
                                    ->orderBy('code')->limit(50)->get()->mapWithKeys(fn (Course $record): array => [$record->id => self::courseLabel($record)])->all())
                                ->getOptionLabelUsing(fn (mixed $value): ?string => ($course = Course::find($value)) ? self::courseLabel($course) : null)
                                ->searchable()
                                ->preload()
                                ->required(),
                            Toggle::make('is_active')
                                ->label('Active qualification')
                                ->default(true)
                                ->columnSpanFull(),
                        ])->columns(2),
                        Section::make('Notes and recording details')
                            ->contained(false)
                            ->schema([
                                TextEntry::make('recorder.name')
                                    ->label('Recorded by')
                                    ->placeholder('Unavailable')
                                    ->visible(fn (?FacultyQualification $record): bool => $record !== null),
                                TextEntry::make('recorded_at')
                                    ->label('Recorded at (Asia/Manila)')
                                    ->dateTime('M j, Y, g:i A')
                                    ->timezone('Asia/Manila')
                                    ->placeholder('Unavailable')
                                    ->visible(fn (?FacultyQualification $record): bool => $record !== null),
                                Textarea::make('notes')
                                    ->rows(3)
                                    ->maxLength(2000)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ])
                    ->columns(1)
                    ->divided()
                    ->columnSpanFull(),
            ]);
    }

    private static function courseLabel(Course $course): string
    {
        $course->loadMissing(['specifications' => fn (HasMany $query): HasMany => $query->where('state', CourseSpecification::StateActive)->latest('id')]);

        return ($course->specifications->first()?->title ?? 'Approved course title unavailable').' ('.$course->code.')';
    }
}
