<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Schemas;

use App\Filament\Resources\FacultyTermLoadOverrides\Pages\CreateFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\EditFacultyTermLoadOverride;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class FacultyTermLoadOverrideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Approved Faculty load')
                    ->description('Record the approved base unit limit and any additional units for this Faculty member and Term. Approval takes place outside TALA; record its authority and reason here.')
                    ->schema([
                        Select::make('faculty_user_id')
                            ->label('Faculty')
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('term_id', $get('term_id')))
                            ->validationMessages(['unique' => 'An exception already exists for this faculty member and Term. Edit the existing exception.'])
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
                        Select::make('term_id')
                            ->label('Term')
                            ->default(fn (CreateFacultyTermLoadOverride|EditFacultyTermLoadOverride $livewire): ?int => $livewire->termId)
                            ->disabled(fn (CreateFacultyTermLoadOverride|EditFacultyTermLoadOverride $livewire): bool => $livewire->termId !== null)
                            ->dehydrated()
                            ->relationship(
                                'term',
                                'label',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->orderByDesc('starts_on'),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('default_max_units_snapshot')
                            ->label('Base teaching-load limit (units)')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.25')
                            ->required(),
                        TextInput::make('approved_overload_units')
                            ->label('Approved additional units')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.25')
                            ->default(0)
                            ->required(),
                        TextInput::make('authority')
                            ->maxLength(255)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active exception')
                            ->default(true),
                        Textarea::make('reason')
                            ->required()
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
