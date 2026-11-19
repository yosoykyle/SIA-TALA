<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\Enrollment;
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

    #[Url(as: 'account')]
    public ?int $accountingEnrollmentId = null;

    private bool $accountingEnrollmentResolved = false;

    private ?Enrollment $accountingEnrollmentCache = null;

    private function accountingEnrollment(): ?Enrollment
    {
        if ($this->accountingEnrollmentId === null) {
            return null;
        }

        if (! $this->accountingEnrollmentResolved) {
            $this->accountingEnrollmentResolved = true;
            $this->accountingEnrollmentCache = Enrollment::query()
                ->with('credentialUser')
                ->find($this->accountingEnrollmentId);
        }

        return $this->accountingEnrollmentCache;
    }

    public function accountingReturnUrl(): ?string
    {
        if (! auth()->user()?->hasRole(User::StaffRoleAccounting) || $this->accountingEnrollmentId === null) {
            return null;
        }
        $case = $this->accountingEnrollment();
        $change = $this->selectedChangeId === null ? null : StudentLifecycleChange::query()->find($this->selectedChangeId);
        if ($case === null || $change === null || (int) $change->enrollment_id !== (int) $case->id
            || (int) $change->student_profile_id !== (int) $case->student_profile_id || (int) $change->term_id !== (int) $case->term_id
            || ! auth()->user()->can('view', $case) || ! auth()->user()->can('view', $change)) {
            return null;
        }

        return EnrollmentResource::getUrl('index', ['tab' => 'student_changes', 'account' => $case->id, 'view' => 'changes']);
    }

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
        if (auth()->user()?->hasRole(User::StaffRoleAccounting)) {
            return 'Original student change';
        }

        return 'Student changes';
    }

    public function getSubheading(): ?string
    {
        if (auth()->user()?->hasRole(User::StaffRoleAccounting)) {
            return 'Read the academic source. Record its Accounting result in Student accounts.';
        }

        return auth()->user()?->hasRole(User::StaffRoleAcademicHead) && ! auth()->user()->hasRole(User::StaffRoleRegistrar)
            ? 'All terms. Inspect recorded changes or filter by term. Back to Academic Oversight returns to your selected term.'
            : 'Browse a change and inspect its original record.';
    }

    public function getBreadcrumbs(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleAccounting)) {
            $returnUrl = $this->accountingReturnUrl();
            $name = $returnUrl === null ? null : $this->accountingEnrollment()?->credentialUser?->getFilamentName();

            return [
                EnrollmentResource::getUrl('index') => 'Overview',
                EnrollmentResource::getUrl('index', ['tab' => 'accounts']) => 'Student accounts',
                ...($returnUrl !== null ? [$returnUrl => $name ?? 'Student account'] : []),
                'Original student change',
            ];
        }
        if (auth()->user()?->hasRole(User::StaffRoleRegistrar)) {
            return [GradesAndCompletion::getUrl() => 'Students Record', 'Lifecycle'];
        }

        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return [AcademicApprovals::getUrl(['termId' => $this->oversightTermId]) => 'Academic Oversight', 'Student lifecycle changes'];
        }

        return parent::getBreadcrumbs();
    }

    protected function getHeaderActions(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleAccounting)) {
            return [];
        }
        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead) && ! auth()->user()->hasRole(User::StaffRoleRegistrar)) {
            return [Action::make('returnToOversight')->label('Back to Academic Oversight')->color('gray')
                ->url(AcademicApprovals::getUrl(['termId' => $this->oversightTermId]))];
        }

        return [
            CreateAction::make()->label('Record student change'),
        ];
    }
}
