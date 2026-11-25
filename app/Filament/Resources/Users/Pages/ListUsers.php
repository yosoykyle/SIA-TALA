<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\StudentProfiles\OngoingStudentSpreadsheetExportService;
use App\Filament\Resources\Users\UserResource;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\NavigationBadgeCounts;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    #[Url(as: 'queue', history: true)]
    public string $queue = 'all';

    public function updatedQueue(): void
    {
        $this->queue = $this->normalizedQueue();
        $this->resetPage();
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        return match ($this->normalizedQueue()) {
            'attention' => 'Accounts waiting for invitation activation, email or MFA verification, or reactivation.',
            'staff' => 'Accounts with assigned institutional staff roles.',
            'multi-role' => 'Accounts assigned to two or more institutional roles or workspaces.',
            'learners' => 'Registered applicant and student accounts.',
            default => 'Manage staff invitations, workspace access, MFA readiness, and account lifecycle.',
        };
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.admin.users.summary-strip')
                ->key('users-summary-strip')
                ->viewData(fn (): array => [
                    'metrics' => app(NavigationBadgeCounts::class)->systemAdminUsers(),
                    'activeQueue' => $this->normalizedQueue(),
                ]),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if (! $query instanceof Builder) {
            return null;
        }

        return match ($this->normalizedQueue()) {
            'attention' => $query->whereIn('status', [
                User::StatusInvitationPending,
                User::StatusVerificationRequired,
                User::StatusDisabled,
            ]),
            'staff' => $query->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', User::staffRoleNames())),
            'multi-role' => $query->has('roles', '>', 1),
            'learners' => $query->whereHas('roles', fn (Builder $roles): Builder => $roles->whereIn('name', ['student', 'applicant'])),
            default => $query,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportActivationStatus')
                ->label('Export student activation status')
                ->color('gray')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->normalizedQueue() === 'learners'
                    && (auth()->user()?->hasRole(User::StaffRoleSystemSuperAdmin) ?? false))
                ->authorize(fn (): bool => auth()->user()?->hasRole(User::StaffRoleSystemSuperAdmin) ?? false)
                ->action(function (): BinaryFileResponse {
                    $userIds = $this->getFilteredTableQuery()->pluck('id');
                    $profiles = StudentProfile::query()
                        ->whereIn('user_id', $userIds)
                        ->with(['user', 'program', 'curriculumVersion'])
                        ->orderBy('student_number')
                        ->get();

                    $exportService = app(OngoingStudentSpreadsheetExportService::class);
                    $path = $exportService->exportActivationStatus($profiles);

                    return $exportService->downloadAndDelete($path, basename($path), 'csv');
                }),
        ];
    }

    public function normalizedQueue(): string
    {
        return match ($this->queue) {
            'attention', 'staff', 'multi-role', 'learners' => $this->queue,
            default => 'all',
        };
    }
}
