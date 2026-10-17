<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Schemas;

use App\Filament\Resources\FacultyTermLoadOverrides\Pages\CreateFacultyTermLoadOverride;
use App\Filament\Resources\FacultyTermLoadOverrides\Pages\EditFacultyTermLoadOverride;
use App\Models\Term;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
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
                Section::make('Approved teaching load')
                    ->description('Use the values in the approved exception.')
                    ->schema([
                        Group::make([
                            Select::make('faculty_user_id')
                                ->label('Teacher')
                                ->placeholder('Choose a teacher')
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
                                ->label('Base limit (units)')
                                ->numeric()
                                ->minValue(0)
                                ->step('0.25')
                                ->helperText(function (CreateFacultyTermLoadOverride|EditFacultyTermLoadOverride $livewire): string {
                                    $termId = $livewire->termId;

                                    if ($termId === null && $livewire instanceof EditFacultyTermLoadOverride) {
                                        $termId = (int) $livewire->getRecord()->term_id;
                                    }

                                    return self::termDefaultDescription($termId);
                                })
                                ->required(),
                            TextInput::make('approved_overload_units')
                                ->label('Approved additional units')
                                ->numeric()
                                ->minValue(0)
                                ->step('0.25')
                                ->default(0)
                                ->required(),
                            Toggle::make('is_active')
                                ->label('Active exception')
                                ->default(true)
                                ->columnSpanFull(),
                        ])
                            ->columns(2)
                            ->columnSpanFull(),
                        Section::make('Approval evidence')
                            ->contained(false)
                            ->schema([
                                TextInput::make('authority')
                                    ->label('Approval reference')
                                    ->helperText('Name the approving person or reference document.')
                                    ->maxLength(255)
                                    ->required(),
                                Textarea::make('reason')
                                    ->label('Reason')
                                    ->required()
                                    ->rows(3)
                                    ->maxLength(2000)
                                    ->columnSpanFull(),
                            ])
                            ->columns(1)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->divided()
                    ->columnSpanFull(),
            ]);
    }

    public static function termDefaultDescription(?int $termId): string
    {
        if ($termId === null) {
            return 'Select a Term to see its default.';
        }

        $default = Term::query()->whereKey($termId)->value('default_max_units');

        if ($default === null) {
            return 'Term default: Not recorded.';
        }

        return 'Term default: '.rtrim(rtrim(number_format((float) $default, 2, '.', ''), '0'), '.').' units per teacher.';
    }
}
