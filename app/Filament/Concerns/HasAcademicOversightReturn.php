<?php

namespace App\Filament\Concerns;

use App\Actions\Authentication\WorkspaceContextResolver;
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

    protected function resolvedOversightTermId(): ?int
    {
        if (is_int($this->oversightTermId) && $this->oversightTermId > 0) {
            return $this->oversightTermId;
        }

        $fromRequest = \Filament\Support\original_request()->integer('oversightTermId');

        return $fromRequest > 0 ? $fromRequest : null;
    }

    /** @return array{oversightTermId?: int} */
    protected function oversightQueryParameters(): array
    {
        $termId = $this->resolvedOversightTermId();

        return $termId !== null ? ['oversightTermId' => $termId] : [];
    }

    protected function oversightReturnUrl(): string
    {
        $termId = $this->resolvedOversightTermId();

        return AcademicApprovals::getUrl($termId !== null ? ['termId' => $termId] : []);
    }

    protected function isAcademicHead(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(User::StaffRoleAcademicHead);
    }

    protected function isAcademicHeadReader(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasRole(User::StaffRoleAcademicHead)) {
            return false;
        }

        if (! $user->hasRole(User::StaffRoleRegistrar)) {
            return true;
        }

        return app(WorkspaceContextResolver::class)->selected($user) === User::StaffRoleAcademicHead;
    }

    protected function isRegistrarActor(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->status === User::StatusActive
            && $user->hasRole(User::StaffRoleRegistrar)
            && ! $this->isAcademicHeadReader();
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
