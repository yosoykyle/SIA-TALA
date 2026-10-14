<?php

namespace App\Filament\Applicant\Pages;

use App\Models\AdmissionApplication;
use App\Models\ApplicationSubmissionVersion;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Url;

class SubmissionReceipt extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.applicant.pages.submission-receipt';

    protected Width|string|null $maxContentWidth = Width::FourExtraLarge;

    #[Url(as: 'application')]
    public ?int $sourceApplicationId = null;

    #[Url(as: 'version')]
    public ?int $submissionVersionId = null;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->hasRole('applicant') && $actor->canAuthenticate();
    }

    public function mount(): void
    {
        $this->receipt();
    }

    public function getTitle(): string
    {
        return 'Application receipt';
    }

    public function receipt(): ApplicationSubmissionVersion
    {
        $application = AdmissionApplication::query()->canonical()->where('user_id', auth()->id())->findOrFail($this->sourceApplicationId);

        return $application->submissionVersions()->with('application')->findOrFail($this->submissionVersionId ?? $application->current_submission_version_id);
    }

    public function getBreadcrumbs(): array
    {
        return [Dashboard::getUrl(['application' => $this->sourceApplicationId]) => 'Home',
            Requirements::getUrl(['application' => $this->sourceApplicationId]) => 'Application status', 'Application receipt'];
    }
}
