<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('manageRoles')
                ->label('Manage roles')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->url(RoleResource::getUrl('index'))
                ->visible(fn (): bool => RoleResource::canAccess()),
        ];
    }
}
