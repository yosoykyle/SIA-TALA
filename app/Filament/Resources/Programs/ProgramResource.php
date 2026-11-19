<?php

namespace App\Filament\Resources\Programs;

use App\Filament\Resources\Programs\Pages\ListPrograms;
use App\Filament\Resources\Programs\Pages\ViewProgram;
use App\Filament\Resources\Programs\RelationManagers\CurriculumVersionsRelationManager;
use App\Filament\Resources\Programs\Schemas\ProgramForm;
use App\Filament\Resources\Programs\Tables\ProgramsTable;
use App\Models\Program;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProgramResource extends Resource
{
    protected static ?string $model = Program::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Programs';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return ProgramForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProgramsTable::configure($table);
    }

    public static function createProgramAction(string $name = 'create'): CreateAction
    {
        return CreateAction::make($name)->label('New program')->model(Program::class)->modal()
            ->modalHeading('New program')
            ->modalDescription('Record a degree or diploma offered by the school.')
            ->modalSubmitActionLabel('Create program')
            ->authorize(fn (): bool => auth()->user()?->can('create', Program::class) ?? false)
            ->schema(fn (Schema $schema): Schema => ProgramForm::configure($schema))
            ->modalWidth(Width::TwoExtraLarge)->stickyModalHeader()->stickyModalFooter()->createAnother()
            ->createAnotherAction(fn (Action $action): Action => $action->label('Create and add another'))
            ->successRedirectUrl(fn (Program $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [
            CurriculumVersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrograms::route('/'),
            'view' => ViewProgram::route('/{record}'),
        ];
    }
}
