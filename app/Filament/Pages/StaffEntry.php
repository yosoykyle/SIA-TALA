<?php

namespace App\Filament\Pages;

use App\Actions\Authentication\WorkspaceContextResolver;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Models\AdmissionApplication;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Auth;

class StaffEntry extends Dashboard
{
    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            $this->redirect(route('filament.admin.auth.login'));

            return;
        }

        $resolver = app(WorkspaceContextResolver::class);
        $selectedContext = $resolver->selected($user);

        if ($selectedContext === null) {
            $available = $resolver->availableContexts($user);
            if (count($available) === 1) {
                $selectedContext = array_key_first($available);
            }
        }

        if ($selectedContext !== null && $selectedContext !== User::StaffRoleRegistrar) {
            $destination = $resolver->destinationFor($user, $selectedContext);
            if ($destination !== null) {
                $this->redirect($destination);

                return;
            }
        }

        $isRegistrar = $selectedContext === User::StaffRoleRegistrar
            || ($selectedContext === null && $user->hasRole(User::StaffRoleRegistrar));

        if ($isRegistrar && $user->can('approve-documents')) {
            $applicationId = request()->query('application');
            if ($applicationId !== null && is_numeric($applicationId)) {
                $application = AdmissionApplicationResource::getEloquentQuery()
                    ->find((int) $applicationId);

                if ($application instanceof AdmissionApplication && AdmissionApplicationResource::canView($application)) {
                    $this->redirect(AdmissionApplicationResource::getUrl('view', ['record' => $application]));

                    return;
                }
            }

            $this->redirect(AdmissionApplicationResource::getUrl('index'));

            return;
        }

        if ($selectedContext !== null) {
            $destination = $resolver->destinationFor($user, $selectedContext);
            if ($destination !== null) {
                $this->redirect($destination);

                return;
            }
        }

        $this->redirect(route('workspace-chooser'));
    }
}
