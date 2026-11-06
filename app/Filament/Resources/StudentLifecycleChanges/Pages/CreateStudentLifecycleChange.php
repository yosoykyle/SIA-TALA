<?php

namespace App\Filament\Resources\StudentLifecycleChanges\Pages;

use App\Actions\StudentLifecycle\Exceptions\StudentLifecycleRuleViolation;
use App\Actions\StudentLifecycle\StudentLifecycleService;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Pages\GradesAndCompletion;
use App\Filament\Resources\StudentLifecycleChanges\Schemas\StudentLifecycleChangeForm;
use App\Filament\Resources\StudentLifecycleChanges\StudentLifecycleChangeResource;
use App\Models\StudentLifecycleChange;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateStudentLifecycleChange extends CreateRecord
{
    protected static string $resource = StudentLifecycleChangeResource::class;

    protected static bool $canCreateAnother = false;

    public function changeStudent(): void
    {
        $this->form->fill(['effective_on' => today('Asia/Manila')]);
        $this->rememberData();
    }

    protected function getFormActions(): array
    {
        return [];
    }

    /** @return list<Action> */
    public function recordingActions(): array
    {
        return [$this->getCancelFormAction(), $this->getCreateFormAction()];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('chooseStudent')
            ->label('Choose another student')->icon('heroicon-o-users')->color('primary')
            ->visible(fn (): bool => filled($this->data['student_profile_id'] ?? null))
            ->requiresConfirmation(fn (): bool => filled($this->data['type'] ?? null))
            ->modalHeading('Choose another student?')
            ->modalDescription('The unsaved change will be cleared. No student change has been recorded.')
            ->modalSubmitActionLabel('Choose another student')
            ->action(fn () => $this->changeStudent())];
    }

    public function getTitle(): string
    {
        return 'Record student change';
    }

    public function getBreadcrumbs(): array
    {
        if (auth()->user()?->hasRole(User::StaffRoleRegistrar)) {
            return [GradesAndCompletion::getUrl() => 'Grades & Completion', StudentLifecycleChangeResource::getUrl('index') => 'Student lifecycle changes', 'Record change'];
        }

        if (auth()->user()?->hasRole(User::StaffRoleAcademicHead)) {
            return [AcademicApprovals::getUrl() => 'Academic Oversight', StudentLifecycleChangeResource::getUrl('index') => 'Student lifecycle changes', 'Record change'];
        }

        return parent::getBreadcrumbs();
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->color('primary')->disabled(fn (): bool => ! $this->canRecordChange())
            ->label(fn (): string => match ($this->data['type'] ?? null) {
                StudentLifecycleChange::TypeLeaveOfAbsence => $this->isPendingApplication() ? 'Record leave approval' : 'Record leave',
                StudentLifecycleChange::TypeWithdrawal => 'Record withdrawal',
                StudentLifecycleChange::TypeReactivation => 'Record return',
                StudentLifecycleChange::TypeTransferOut => 'Record transfer',
                StudentLifecycleChange::TypeProgramShift => 'Record program shift',
                StudentLifecycleChange::TypeInactivation => 'Record inactivity',
                default => 'Record change',
            });
    }

    public function canRecordChange(): bool
    {
        return ($this->data['impact_confirmed'] ?? false)
            && StudentLifecycleChangeForm::previewFor($this->data ?? [])['valid'];
    }

    private function isPendingApplication(): bool
    {
        return (bool) data_get(StudentLifecycleChangeForm::previewFor($this->data ?? []), 'preview.pending_application', false);
    }

    /** @param array<string,mixed> $data */
    protected function handleRecordCreation(array $data): StudentLifecycleChange
    {
        try {
            return app(StudentLifecycleService::class)->record($data, auth()->user());
        } catch (StudentLifecycleRuleViolation $exception) {
            throw ValidationException::withMessages([
                'data.impact_confirmed' => $exception->getMessage(),
            ]);
        }
    }
}
