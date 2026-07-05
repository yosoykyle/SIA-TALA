<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class FacultyTermLoadOverrideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Approved Term Load Override')
                    ->description('Record only the final approved term-specific overload value. Approval workflow and evidence stay outside TALA.')
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
                        Select::make('term_id')
                            ->label('Term')
                            ->relationship(
                                'term',
                                'label',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->orderByDesc('starts_on'),
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        TextInput::make('default_max_units_snapshot')
                            ->label('Default Max Units Snapshot')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.25')
                            ->required(),
                        TextInput::make('approved_overload_units')
                            ->label('Approved Overload Units')
                            ->numeric()
                            ->minValue(0)
                            ->step('0.25')
                            ->default(0)
                            ->required(),
                        TextInput::make('authority')
                            ->maxLength(255)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active Override')
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
