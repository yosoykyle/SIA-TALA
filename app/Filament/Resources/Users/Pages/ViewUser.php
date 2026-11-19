<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Tables\UsersTable;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    /** @return array<string|int, string> */
    public function getBreadcrumbs(): array
    {
        return [
            UserResource::getUrl('index') => 'Users & Access',
            $this->getRecord()->name ?: $this->getRecord()->email ?: 'Account details',
        ];
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            UsersTable::resendInvitationAction(),
            UsersTable::reactivateAction(),
            UsersTable::changeStaffAccessAction(),
            UsersTable::changeStaffEmailAction(),
            UsersTable::sendRecoveryLinkAction(),
            UsersTable::resetMfaAction(),
            UsersTable::disableAction(),
        ];
    }
}
