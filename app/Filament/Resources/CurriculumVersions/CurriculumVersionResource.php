<?php

namespace App\Filament\Resources\CurriculumVersions;

use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Schemas\CurriculumVersionForm;
use App\Filament\Resources\CurriculumVersions\Tables\CurriculumVersionsTable;
use App\Models\CurriculumVersion;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CurriculumVersionResource extends Resource
{
    protected static ?string $model = CurriculumVersion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Registrar';

    protected static ?string $navigationLabel = 'Curricula';

    protected static ?int $navigationSort = 24;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Registrar';
    }

    public static function form(Schema $schema): Schema
    {
        return CurriculumVersionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CurriculumVersionsTable::configure($table);
    }

    public static function createDraftAction(?int $programId = null, ?int $oversightTermId = null, string $name = 'create'): CreateAction
    {
        return CreateAction::make($name)->label('New curriculum')->model(CurriculumVersion::class)->modal()
            ->modalHeading('New curriculum')
            ->modalDescription('Name the study plan, then add its subjects by year level and semester.')
            ->authorize(fn (): bool => auth()->user()?->can('create', CurriculumVersion::class) ?? false)
            ->schema(fn (Schema $schema): Schema => CurriculumVersionForm::configure($schema, $programId))
            ->mutateDataUsing(fn (array $data): array => CurriculumVersionForm::draftAttributes($programId !== null ? [...$data, 'program_id' => $programId] : $data))
            ->modalSubmitActionLabel('Create draft')->modalWidth(Width::TwoExtraLarge)->stickyModalHeader()->stickyModalFooter()->createAnother(false)
            ->successRedirectUrl(fn (CurriculumVersion $record): string => static::getUrl('review', array_filter([
                'record' => $record, 'programId' => $record->program_id, 'oversightTermId' => $oversightTermId,
            ], fn (mixed $value): bool => $value !== null)));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurriculumVersions::route('/'),
            'review' => ReviewCurriculumVersion::route('/{record}/review'),
        ];
    }
}
