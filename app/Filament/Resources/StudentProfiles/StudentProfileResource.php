<?php

namespace App\Filament\Resources\StudentProfiles;

use App\Filament\Resources\StudentProfiles\Pages\EditStudentProfile;
use App\Filament\Resources\StudentProfiles\Pages\ListStudentProfiles;
use App\Filament\Resources\StudentProfiles\Pages\ViewStudentProfile;
use App\Filament\Resources\StudentProfiles\RelationManagers\ChecklistItemsRelationManager;
use App\Filament\Resources\StudentProfiles\RelationManagers\HoldsRelationManager;
use App\Models\StudentProfile;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class StudentProfileResource extends Resource
{
    protected static ?string $model = StudentProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Student Profiles';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student Profile Details')
                    ->schema([
                        TextInput::make('student_id')
                            ->disabled()
                            ->dehydrated(),
                        TextInput::make('lrn')
                            ->required()
                            ->maxLength(255),
                        Select::make('program_id')
                            ->relationship('program', 'name')
                            ->required(),
                        TextInput::make('year_level')
                            ->required()
                            ->integer(),
                        Select::make('operational_status')
                            ->required()
                            ->options([
                                'Active' => 'Active',
                                'enrolled' => 'Enrolled',
                                'probationary' => 'Probationary',
                                'irregular' => 'Irregular',
                                'dropped' => 'Dropped',
                                'LOA' => 'LOA',
                                'AWOL' => 'AWOL',
                                'Archived' => 'Archived',
                            ]),
                        TextInput::make('modality')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('current_balance')
                            ->required()
                            ->numeric(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withDuplicates())
            ->columns([
                TextColumn::make('student_id')
                    ->label('Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Student Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lrn')
                    ->label('LRN')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('program.name')
                    ->label('Program')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('year_level')
                    ->label('Year Level')
                    ->sortable(),
                TextColumn::make('operational_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'enrolled', 'Active' => 'success',
                        'probationary', 'irregular' => 'warning',
                        'dropped', 'LOA', 'AWOL', 'Archived' => 'danger',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('modality')
                    ->label('Modality')
                    ->sortable(),
                TextColumn::make('current_balance')
                    ->label('Current Balance')
                    ->money('PHP')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChecklistItemsRelationManager::class,
            HoldsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentProfiles::route('/'),
            'view' => ViewStudentProfile::route('/{record}'),
            'edit' => EditStudentProfile::route('/{record}/edit'),
        ];
    }
}
