<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\StudentLifecycleChange;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

class ListStudentLifecycleChanges extends ListRecords
{
    protected static string $resource = StudentLifecycleChangeResource::class;

    #[Url]
    public ?int $oversightTermId = null;

    #[Url(as: 'change')]
    public ?int $selectedChangeId = null;

    public function bootedInteractsWithTable(): void
    {
        parent::bootedInteractsWithTable();

        if (! $this->shouldMountInteractsWithTable || $this->selectedChangeId === null || request()->query('page') !== null) {
            return;
        }

        $change = $this->getFilteredTableQuery()->whereKey($this->selectedChangeId)->first();
        if (! $change) {
            return;
        }

        Gate::authorize('view', $change);
        $preceding = $this->getFilteredTableQuery()
            ->where(fn (Builder $query): Builder => $query
                ->where('effective_on', '>', $change->effective_on->toDateString())
                ->orWhere(fn (Builder $query): Builder => $query
                    ->whereDate('effective_on', $change->effective_on->toDateString())
                    ->where('id', '>', $change->id)))
            ->count();
        $this->setPage(intdiv($preceding, max(1, (int) $this->getTableRecordsPerPage())) + 1, $this->getTablePaginationPageName());
    }

    public function selectedChange(): ?StudentLifecycleChange
    {
        $records = $this->getTableRecords();
        $change = $this->selectedChangeId === null ? $records->first() : $records->firstWhere('id', $this->selectedChangeId);
        if (! $change) {
            return null;
        }

        Gate::authorize('view', $change);

        return $change->loadMissing([
            'recorder', 'enrollment',
            'programShiftCredits.curriculumEntry.courseSpecification.course', 'programShiftCredits.sourceCourse',
        ]);
    }

    public function selectChange(int $recordId): void
    {
        abort_unless($this->getTableRecords()->contains('id', $recordId), 404);
        $this->selectedChangeId = $recordId;
        $this->dispatch('record-selected');
    }

    public function clearLifecycleFilters(): void
    {
        $this->resetTableFiltersForm();
        $this->tableSearch = '';
        $this->selectedChangeId = null;
        $this->resetPage();
    }

    public function getTitle(): string
    {
        return 'Student changes';
    }

    public function getSubheading(): ?string
    {
        return auth()->user()?->hasRole(User::StaffRoleAcademicHead) && ! auth()->user()->hasRole(User::StaffRoleRegistrar)
            ? 'All terms. Inspect recorded changes or filter by term. Back to Academic Oversight returns to your selected term.'
            : 'Browse a change and inspect its original record.';
    }

    public function getBreadcrumbs(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleRegistrar)) {
            return [GradesAndCompletion::getUrl() => 'Grades & Completion', 'Lifecycle'];
        }

        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return [AcademicApprovals::getUrl(['termId' => $this->oversightTermId]) => 'Academic Oversight', 'Student lifecycle changes'];
        }

        return parent::getBreadcrumbs();
    }

    protected function getHeaderActions(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead) && ! auth()->user()->hasRole(User::StaffRoleRegistrar)) {
            return [Action::make('returnToOversight')->label('Back to Academic Oversight')->color('gray')
                ->url(AcademicApprovals::getUrl(['termId' => $this->oversightTermId]))];
        }

        return [
            CreateAction::make()->label('Record student change'),
        ];
    }
}
