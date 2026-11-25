<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Pages\GovernanceAudit;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return UsersTable::displayName($this->userRecord());
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getBreadcrumb(): string
    {
        return $this->getTitle();
    }

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            UserResource::getUrl('index') => 'Users & Access',
            $this->getBreadcrumb(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.admin.users.profile'),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function resendInvitationAction(): Action
    {
        return UsersTable::resendInvitationAction()
            ->color('primary')
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function correctPendingEmailAction(): Action
    {
        return UsersTable::correctPendingEmailAction()
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function cancelInvitationAction(): Action
    {
        return UsersTable::cancelInvitationAction()
            ->size(Size::Small)
            ->after(fn () => $this->redirect(UserResource::getUrl('index'), navigate: true));
    }

    public function resendVerificationAction(): Action
    {
        return UsersTable::resendVerificationAction()
            ->color('primary')
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function reactivateAction(): Action
    {
        return UsersTable::reactivateAction()
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function editRoleAndDetailsAction(): Action
    {
        return UsersTable::editRoleAndDetailsAction()
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function changeSignInEmailAction(): Action
    {
        return UsersTable::changeSignInEmailAction()
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function sendRecoveryLinkAction(): Action
    {
        return UsersTable::sendRecoveryLinkAction()
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function resetMfaAction(): Action
    {
        return UsersTable::resetMfaAction()
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function disableAction(): Action
    {
        return UsersTable::disableAction()
            ->size(Size::Small)
            ->after(fn () => $this->refreshProfile());
    }

    public function viewFullActivityLogAction(): Action
    {
        return Action::make('viewFullActivityLog')
            ->label('View full activity log')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->color('gray')
            ->size(Size::Small)
            ->url(fn (): string => GovernanceAudit::getUrl([
                'actor' => (string) $this->userRecord()->getKey(),
            ]));
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function profileData(): array
    {
        return UserInfolist::profileData($this->userRecord());
    }

    private function userRecord(): User
    {
        $record = $this->getRecord();
        abort_unless($record instanceof User, 404);

        return $record;
    }

    private function refreshProfile(): void
    {
        $record = $this->userRecord();
        $record->refresh();
        $record->unsetRelation('roles');
        $record->unsetRelation('staffAccessProfile');
        $record->unsetRelation('staffInvitations');
        $record->unsetRelation('pendingEmailChanges');
        $record->unsetRelation('studentProfile');
        $record->unsetRelation('currentAdmissionApplication');
        unset($this->profileData);
    }
}
