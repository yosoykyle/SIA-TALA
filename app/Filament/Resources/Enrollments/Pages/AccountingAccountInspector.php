<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Models\User;

class AccountingAccountInspector extends ViewEnrollment
{
    public string $enrollmentView = 'overview';

    public ?string $returnQueue = null;

    public ?int $paymentProofId = null;

    public ?string $returnSearch = null;

    /** @var array<string, mixed> */
    public array $returnFilters = [];

    public function mount(int|string $record): void
    {
        abort_unless(auth()->user()?->hasRole(User::StaffRoleAccounting), 403);
        parent::mount($record);
    }

    public function getView(): string
    {
        return 'filament.admin.enrollments.accounting-inspector';
    }

    public function isAccountingWorkspace(): bool
    {
        return true;
    }
}
