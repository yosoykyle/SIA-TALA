<?php

namespace App\Filament\Pages;

use App\Actions\Scheduling\TermCoverageProjection;
use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Filament\Resources\TermOfferings\TermOfferingResource;
use App\Models\Term;
use App\Models\User;
use App\Support\PlanningTableRecords;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use UnitEnum;

class TermOverview extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Overview';

    protected static ?string $slug = 'overview';

    protected static ?int $navigationSort = 0;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'plan';

    protected string $view = 'filament.pages.term-overview';

    #[Computed]
    public function coverage(): ?array
    {

        $term = $this->termId ? Term::find($this->termId) : null;

        return $term ? app(TermCoverageProjection::class)->forTerm($term) : null;

    }

    public function table(Table $table): Table
    {

        return $table->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage): LengthAwarePaginator => PlanningTableRecords::paginate(collect($this->coverage['rows'] ?? [])->keyBy('id')->all(), ['program_name', 'curriculum_label', 'year_level', 'section_references', 'status'], $search, $sortColumn, $sortDirection, $page, $recordsPerPage))

            ->columns([

                TextColumn::make('year_level')->label('Year level')->sortable()->formatStateUsing(fn ($state): string => $state ? (str_contains(strtolower($state), 'year') ? $state : 'Year '.$state) : 'Not associated'),

                TextColumn::make('section_references')->label('Student sections')->listWithLineBreaks()->wrap()->placeholder('None recorded')

                    ->description(fn (array $record): string => $record['student_count'].' confirmed learners'.($record['has_cross_year_sections'] ? ' · spans curriculum years' : '')),

                TextColumn::make('class_count')->label('Classes')->numeric()->sortable(),

                TextColumn::make('meeting_count')->label('Weekly meetings')->numeric()->sortable(),

                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'Prepared' ? 'success' : ($state === 'Not set up' ? 'gray' : 'warning'))

                    ->tooltip(fn (array $record): string => $record['reason'])->wrap(),

            ])

            ->groups([Group::make('program_name')->label('Program')->getTitleFromRecordUsing(fn (array $record): string => $record['program_name'].' · '.$record['curriculum_label'])])

            ->defaultGroup('program_name')->groupingSettingsHidden()->searchable()->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)

            ->emptyStateHeading('No active curriculum entries for this Term type')

            ->emptyStateDescription('Review Catalog & Curricula or record approved additional classes. No program or year level is inferred.')

            ->recordActions([

                Action::make('setup')->label(fn (array $record): string => $record['class_count'] ? 'Review classes' : (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'Review year level' : 'Set up year level'))

                    ->color('gray')->url(fn (array $record): string => $record['class_count']

                        ? TermClasses::getUrl(['termId' => $this->termId, 'programId' => $record['program_id'], 'yearLevel' => $record['year_level']])

                        : TermOfferingResource::getUrl('index', ['termId' => $this->termId, 'programId' => $record['program_id'], 'curriculumVersionId' => $record['curriculum_version_id'], 'yearLevel' => $record['year_level']])),

            ]);

    }
}
