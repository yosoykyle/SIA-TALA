<?php

namespace App\Filament\Resources\FacultyQualifications\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FacultyQualificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Approved Faculty Qualification')
                    ->description('Record the final approved faculty-to-course qualification mapping. External evidence and approval workflow remain outside TALA.')
                    ->schema([
                        Select::make('faculty_user_id')
                            ->label('Faculty')
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
                            ->label('Course')
                            ->relationship(
                                'course',
                                'code',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->orderBy('code'),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active Qualification')
                            ->default(true),
                        Select::make('recorded_by')
                            ->label('Recorded By')
                            ->relationship(
                                'recorder',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->where('status', User::StatusActive)
                                    ->orderBy('name'),
                            )
                            ->searchable()
                            ->preload()
                            ->default(fn (): ?int => auth()->id()),
                        DateTimePicker::make('recorded_at')
                            ->seconds(false)
                            ->default(now())
                            ->required(),
                        Textarea::make('notes')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
