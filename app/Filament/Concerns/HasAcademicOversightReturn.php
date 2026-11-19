<?php

namespace App\Filament\Concerns;

use App\Filament\Pages\AcademicApprovals;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Provides shared Academic Oversight term context and return navigation for oversight-reachable pages.
 */
trait HasAcademicOversightReturn
{
    #[Url]
    public ?int $oversightTermId = null;

    protected function oversightReturnUrl(): string
    {
        $parameters = is_int($this->oversightTermId) && $this->oversightTermId > 0
            ? ['termId' => $this->oversightTermId]
            : [];

        return AcademicApprovals::getUrl($parameters);
    }

    protected function isAcademicHead(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(User::StaffRoleAcademicHead);
    }

    protected function isAcademicHeadReader(): bool
    {
        $user = auth()->user();

        return $this->isAcademicHead() && ! $user->hasRole(User::StaffRoleRegistrar);
    }

    protected function returnToOversightAction(string $label = 'Academic Oversight'): Action
    {
        return Action::make('returnToOversight')
            ->label($label)
            ->icon(Heroicon::OutlinedArrowLeft)
            ->color('gray')
            ->url(fn (): string => $this->oversightReturnUrl());
    }
}
