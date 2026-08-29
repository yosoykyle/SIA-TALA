<?php

namespace App\Filament\Pages;

use App\Actions\AcademicSetup\ActivateProgramAuthority;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\CourseSpecifications\CourseSpecificationResource;
use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use App\Filament\Resources\ImportBatches\ImportBatchResource;
use App\Filament\Resources\Programs\ProgramResource;
use App\Models\Course;
use App\Models\CourseSpecification;
use App\Models\CurriculumVersion;
use App\Models\ImportBatch;
use App\Models\Program;
use App\Models\ProgramAuthority;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

final class CatalogCurriculaWorkbench extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Academic Planning';

    protected static ?string $navigationLabel = 'Catalog & Curricula';

    protected static ?string $title = 'Catalog & Curricula';

    protected string $view = 'filament.pages.catalog-curricula-workbench';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole([
            User::StaffRoleRegistrar,
            User::StaffRoleAcademicHead,
        ]);
    }

    /** @return list<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return [];
        }

        return [
            Action::make('recordProgramAuthority')
                ->label('Record Program authority')
                ->schema([
                    Select::make('program_id')->label('Program')->options(Program::query()->orderBy('code')->pluck('code', 'id'))->required()->searchable(),
                    TextInput::make('authority_type')->required()->maxLength(64),
                    TextInput::make('authority_reference')->required()->maxLength(255),
                    TextInput::make('regulator')->required()->maxLength(128),
                    DatePicker::make('effective_from')->required(),
                    DatePicker::make('effective_until')->afterOrEqual('effective_from'),
                    TextInput::make('curriculum_source_reference')->required()->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $program = Program::query()->findOrFail((int) $data['program_id']);
                    Gate::forUser($actor)->authorize('update', $program);
                    ProgramAuthority::query()->create([
                        ...$data,
                        'state' => ProgramAuthority::StateDraft,
                        'recorded_by' => $actor->id,
                        'recorded_at' => now(),
                    ]);
                    Notification::make()->title('Draft Program authority recorded')->body('Review the source, then activate it separately.')->success()->send();
                }),
            ActionGroup::make([
                Action::make('activateProgramAuthority')
                    ->label('Activate reviewed Program authority')
                    ->color('success')
                    ->schema([
                        Select::make('program_authority_id')
                            ->label('Draft authority')
                            ->options(ProgramAuthority::query()->with('program')->where('state', ProgramAuthority::StateDraft)->get()->mapWithKeys(
                                fn (ProgramAuthority $authority): array => [$authority->id => $authority->program?->code.' · '.$authority->authority_reference],
                            ))
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (array $data): void {
                        $actor = auth()->user();
                        abort_unless($actor instanceof User, 403);
                        app(ActivateProgramAuthority::class)->execute(
                            ProgramAuthority::query()->findOrFail((int) $data['program_authority_id']),
                            $actor,
                        );
                        Notification::make()->title('Program authority activated')->success()->send();
                    }),
            ])
                ->label('More authority actions')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->color('gray'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Program::query()->with(['authorities', 'curriculumVersions']))
            ->columns([
                TextColumn::make('code')
                    ->label('Program')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Authoritative identity')
                    ->searchable()
                    ->wrap(),
                IconColumn::make('is_active')
                    ->label('Program active')
                    ->boolean(),
                TextColumn::make('active_authority')
                    ->label('Current authority')
                    ->state(function (Program $record): string {
                        $authority = $record->authorities->firstWhere('state', ProgramAuthority::StateActive);

                        return $authority instanceof ProgramAuthority
                            ? (string) $authority->authority_reference
                            : 'Action required';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Action required' ? 'warning' : 'success'),
                TextColumn::make('curriculum_versions_count')
                    ->label('Curriculum history')
                    ->counts('curriculumVersions')
                    ->numeric(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active Program'),
            ])
            ->recordUrl(fn (Program $record): string => ProgramResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No Program authority records')
            ->emptyStateDescription('Create the Program identity first, then record and separately activate its external authority.')
            ->emptyStateIcon(Heroicon::OutlinedBookOpen);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'summary' => [
                ['label' => 'Programs', 'value' => Program::query()->count(), 'detail' => ProgramAuthority::query()->where('state', ProgramAuthority::StateActive)->count().' active authorities'],
                ['label' => 'Courses', 'value' => Course::query()->count(), 'detail' => CourseSpecification::query()->where('state', CourseSpecification::StateActive)->count().' active revisions'],
                ['label' => 'Curricula', 'value' => CurriculumVersion::query()->count(), 'detail' => CurriculumVersion::query()->where('state', CurriculumVersion::StateActive)->count().' active versions'],
                ['label' => 'Import evidence', 'value' => ImportBatch::query()->count(), 'detail' => 'Preview and findings remain attributable'],
            ],
            'destinations' => [
                ['label' => 'Academic readiness', 'description' => 'Inspect existing activation checks and their stated blockers.', 'url' => AcademicReadiness::getUrl()],
                ['label' => 'Programs & authority', 'description' => 'Record external authority and inspect effective history.', 'url' => ProgramResource::getUrl()],
                ['label' => 'Courses', 'description' => 'Maintain stable course identity.', 'url' => CourseResource::getUrl()],
                ['label' => 'Course revisions', 'description' => 'Manage units, classifications, modes, and weekly requirements.', 'url' => CourseSpecificationResource::getUrl()],
                ['label' => 'Curriculum versions', 'description' => 'Review grouped curriculum sheets and activation evidence.', 'url' => CurriculumVersionResource::getUrl()],
                ['label' => 'Import previews', 'description' => 'Inspect fixed-contract previews, blockers, and immutable import evidence.', 'url' => ImportBatchResource::getUrl()],
            ],
            'readOnly' => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? true,
        ];
    }
}
