<?php

namespace App\Filament\Applicant\Pages;

use App\Models\ApplicantIntake;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.applicant.pages.dashboard';

    /**
     * Get the authenticated user's applicant intake record with relationships.
     */
    public function getIntake(): ?ApplicantIntake
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return ApplicantIntake::query()
            ->with(['checklistItems.reviewer', 'documentUploads.registrarReviewer', 'program', 'term'])
            ->where('user_id', $user->id)
            ->first();
    }
}
