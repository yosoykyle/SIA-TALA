<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\TermPlanning\TermPlanningCluster;
use App\Models\SectionMeeting;
use App\Models\TermCalendarPackage;
use App\Models\User;
use App\Support\TeachingWeekSummary;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class TermCalendar extends TermPlanningWorkbench implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = TermPlanningCluster::class;

    protected static ?string $navigationLabel = 'Calendar';

    protected static ?string $title = 'Calendar';

    protected static ?string $slug = 'calendar';

    protected static ?int $navigationSort = 1;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static string $planningTab = 'overview';

    protected string $view = 'filament.pages.term-calendar';

    public function teachingWeekRows(TermCalendarPackage $package): array
    {
        return app(TeachingWeekSummary::class)->rows($package);
    }

    public function table(Table $table): Table
    {

        return $table->records(function (): array {

            $package = TermCalendarPackage::query()->where('term_id', $this->termId)

                ->whereIn('state', [TermCalendarPackage::StateDraft, TermCalendarPackage::StateActive])->latest('version')->with('teachingGridRows')->first();

            return $package?->teachingGridRows->sortBy('day_of_week')->mapWithKeys(fn ($row): array => [$row->id => [

                'day' => SectionMeeting::dayOptions()[$row->day_of_week] ?? 'Unknown day',

                'hours' => CarbonImmutable::parse($row->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($row->ends_at)->format('g:i A'),

                'breaks' => collect($row->breaks ?? [])->map(fn (array $break): string => CarbonImmutable::parse($break['starts_at'])->format('g:i A').'–'.CarbonImmutable::parse($break['ends_at'])->format('g:i A'))->implode(', ') ?: 'None recorded',

            ]])->all() ?? [];

        })->columns([TextColumn::make('day'), TextColumn::make('hours')->label('Teaching hours'), TextColumn::make('breaks')->wrap()])

            ->paginated(false)->emptyStateHeading('No approved teaching days recorded')
            ->emptyStateDescription(fn (): string => auth()->user()?->hasRole(User::StaffRoleAcademicHead) ? 'The Registrar records the teaching calendar before classes can be planned.' : 'Record the teaching calendar before planning classes.');

    }
}
