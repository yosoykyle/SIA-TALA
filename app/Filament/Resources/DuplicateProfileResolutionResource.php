<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DuplicateProfileResolutionResource\Pages\CreateDuplicateProfileResolution;
use App\Filament\Resources\DuplicateProfileResolutionResource\Pages\ListDuplicateProfileResolutions;
use App\Models\DuplicateProfileResolution;
use App\Models\StudentProfile;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class DuplicateProfileResolutionResource extends Resource
{
    protected static ?string $model = DuplicateProfileResolution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Duplicate Resolutions';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Duplicate Profile Resolution')
                    ->schema([
                        Select::make('duplicate_student_id')
                            ->label('Duplicate Student Profile')
                            ->relationship('duplicateStudent', 'student_id', fn ($query) => $query->withDuplicates())
                            ->getOptionLabelFromRecordUsing(fn (StudentProfile $record) => "{$record->student_id} - {$record->user?->name}")
                            ->searchable()
                            ->required(),
                        Select::make('primary_student_id')
                            ->label('Primary Student Profile')
                            ->relationship('primaryStudent', 'student_id', fn ($query) => $query->withDuplicates())
                            ->getOptionLabelFromRecordUsing(fn (StudentProfile $record) => "{$record->student_id} - {$record->user?->name}")
                            ->searchable()
                            ->required(),
                        Select::make('resolution_type')
                            ->label('Resolution Type')
                            ->options([
                                'LINKED_DUPLICATE' => 'Linked Duplicate (Archived)',
                                'NOT_DUPLICATE' => 'Not Duplicate',
                                'KEEP_SEPARATE' => 'Keep Separate',
                            ])
                            ->required(),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->required()
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('duplicateStudent.student_id')
                    ->label('Duplicate Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('duplicateStudent.user.name')
                    ->label('Duplicate Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('primaryStudent.student_id')
                    ->label('Primary Student ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('primaryStudent.user.name')
                    ->label('Primary Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('resolution_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'LINKED_DUPLICATE' => 'danger',
                        'NOT_DUPLICATE' => 'success',
                        'KEEP_SEPARATE' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('reason')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('resolver.name')
                    ->label('Resolved By')
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDuplicateProfileResolutions::route('/'),
            'create' => CreateDuplicateProfileResolution::route('/create'),
        ];
    }
}
