<?php

namespace App\Filament\Resources\CurriculumVersions;

use App\Actions\AcademicSetup\CurriculumVersionLifecycleService;
use App\Filament\Resources\CurriculumVersions\Pages\ImportCurriculumSpreadsheet;
use App\Filament\Resources\CurriculumVersions\Pages\ListCurriculumVersions;
use App\Filament\Resources\CurriculumVersions\Pages\ReviewCurriculumVersion;
use App\Filament\Resources\CurriculumVersions\Schemas\CurriculumVersionForm;
use App\Filament\Resources\CurriculumVersions\Tables\CurriculumVersionsTable;
use App\Models\AcademicYear;
use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
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
        return CreateAction::make($name)
            ->label('New curriculum')
            ->model(CurriculumVersion::class)
            ->modal()
            ->modalHeading('New curriculum')
            ->modalDescription('Start blank or copy from an existing curriculum, assign a starting batch, and build subjects year by year.')
            ->authorize(fn (): bool => auth()->user()?->can('create', CurriculumVersion::class) ?? false)
            ->schema(fn (Schema $schema): Schema => CurriculumVersionForm::configure($schema, $programId))
            ->modalSubmitActionLabel(fn (): string => $programId !== null && CurriculumVersionForm::isProgramInactive($programId)
                ? 'Activate and proceed'
                : 'Create draft')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->createAnother(false)
            ->using(function (array $data, CreateAction $action) use ($programId): CurriculumVersion {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                $resolvedProgramId = $programId ?? (int) ($data['program_id'] ?? 0);
                $program = Program::query()->findOrFail($resolvedProgramId);
                $startingBatch = filled($data['starting_academic_year_id'] ?? null)
                    ? AcademicYear::query()->find((int) $data['starting_academic_year_id'])
                    : null;
                $effectiveEntryTermId = filled($data['effective_entry_term_id'] ?? null)
                    ? (int) $data['effective_entry_term_id']
                    : null;
                $name = filled($data['name'] ?? null) ? trim((string) $data['name']) : null;
                $activateIfInactive = (bool) ($data['activate_program_if_inactive'] ?? false);
                $mode = (string) ($data['creation_mode'] ?? 'blank');
                $service = app(CurriculumVersionLifecycleService::class);
                $versionCode = CurriculumVersionForm::draftCodeFor($program->id);

                try {
                    if ($mode === 'copy' && filled($data['source_curriculum_version_id'] ?? null)) {
                        $source = CurriculumVersion::query()
                            ->where('program_id', $program->id)
                            ->findOrFail((int) $data['source_curriculum_version_id']);

                        return $service->copyToDraft(
                            actor: $actor,
                            source: $source,
                            versionCode: $versionCode,
                            name: $name,
                            effectiveEntryTermId: $effectiveEntryTermId,
                            startingBatch: $startingBatch,
                            activateProgramIfInactive: $activateIfInactive,
                        );
                    }

                    return $service->createDraft(
                        actor: $actor,
                        program: $program,
                        versionCode: $versionCode,
                        name: $name,
                        effectiveEntryTermId: $effectiveEntryTermId,
                        startingBatch: $startingBatch,
                        activateProgramIfInactive: $activateIfInactive,
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Could not create draft curriculum')
                        ->body(collect($exception->errors())->flatten()->implode(' '))
                        ->danger()
                        ->send();
                    $action->halt();

                    throw $exception;
                }
            })
            ->successRedirectUrl(fn (CurriculumVersion $record): string => static::getUrl('review', array_filter([
                'record' => $record,
                'programId' => $record->program_id,
                'oversightTermId' => $oversightTermId,
            ], fn (mixed $value): bool => $value !== null)));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCurriculumVersions::route('/'),
            'review' => ReviewCurriculumVersion::route('/{record}/review'),
            'import' => ImportCurriculumSpreadsheet::route('/{record}/import'),
        ];
    }
}
