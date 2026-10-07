<?php

namespace App\Filament\Pages;

use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Filament\Applicant\Pages\Application as ApplicantApplication;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Models\AdmissionApplication;
use App\Models\User;
use App\Queries\Admissions\AssistedDraftApplicantQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

class AssistedAdmissionApplication extends ApplicantApplication
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'admissions/assisted-entry';

    protected string $view = 'filament.pages.assisted-admission-application';

    public int $applicantId;

    public function mount(): void
    {
        $this->applicantId = request()->integer('applicant');
        $owner = $this->applicationOwner();
        abort_unless($owner instanceof User, 404);

        if ($this->sourceApplicationId !== null) {
            $source = AdmissionApplication::query()->canonical()
                ->whereBelongsTo($owner, 'user')
                ->findOrFail($this->sourceApplicationId);
            if (! in_array($source->application_state, [AdmissionApplication::StateDraft, AdmissionApplication::StateActionNeeded], true)) {
                $this->redirect($this->nonDraftRedirectUrl($source));

                return;
            }
        }

        parent::mount();

        $application = $this->currentApplication();
        $latestAssistance = null;
        if ($application instanceof AdmissionApplication) {
            $latestAssistance = Activity::forSubject($application)
                ->where('event', 'admission_assisted_draft_saved')
                ->latest('id')
                ->first();
        }

        if ($latestAssistance) {
            $reason = $latestAssistance->getExtraProperty('reason') ?? $latestAssistance->getExtraProperty('assistance_reason');
            $authRef = $latestAssistance->getExtraProperty('authority_reference') ?? $latestAssistance->getExtraProperty('routine_reference_no');
            $evRef = $latestAssistance->getExtraProperty('evidence_reference') ?? $latestAssistance->getExtraProperty('applicant_facing_reference_no');

            if (blank($this->data['assistance_reason'] ?? null) && filled($reason)) {
                $this->data['assistance_reason'] = $reason;
            }
            if (blank($this->data['assistance_authority_reference'] ?? null) && filled($authRef)) {
                $this->data['assistance_authority_reference'] = $authRef;
            }
            if (blank($this->data['assistance_evidence_reference'] ?? null) && filled($evRef)) {
                $this->data['assistance_evidence_reference'] = $evRef;
            }
        }
    }

    public static function canAccess(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User
            && $actor->hasRole(User::StaffRoleRegistrar)
            && $actor->canAuthenticate()
            && $actor->can('approve-documents');
    }

    public function getTitle(): string
    {
        return 'Registrar-assisted Application Draft';
    }

    public function getBreadcrumbs(): array
    {
        return [
            AdmissionApplicationResource::getUrl() => 'Admissions',
            'Prepare draft',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('discardDraft')
                ->modalSubmitActionLabel('Discard Applicant draft')
                ->label('Discard draft')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Only this unsubmitted draft and its temporary uploads will be removed. Submitted history is never deleted.')
                ->visible(function (): bool {
                    $application = $this->currentApplication();

                    return $application instanceof AdmissionApplication
                        && $application->application_state === AdmissionApplication::StateDraft
                        && $application->current_submission_version_id === null;
                })
                ->action(function (): void {
                    $application = $this->currentApplication();
                    $applicant = $this->applicationOwner();
                    $actor = $this->actingUser();
                    abort_unless($application instanceof AdmissionApplication
                        && $applicant instanceof User
                        && $actor instanceof User, 404);

                    app(DiscardAdmissionApplication::class)->execute($application, $applicant, $actor);
                    $this->dispatch('draft-saved');
                    Notification::make()->title('Draft discarded')->success()->send();
                    $this->redirect($this->afterDiscardUrl());
                }),
        ];
    }

    protected function applicationOwner(): ?User
    {
        if (! isset($this->applicantId) || $this->applicantId < 1) {
            return null;
        }

        $applicant = User::query()
            ->whereKey($this->applicantId)
            ->where('status', User::StatusActive)
            ->whereNotNull('email_verified_at')
            ->first();

        return $applicant instanceof User && $applicant->hasRole('applicant')
            ? $applicant
            : null;
    }

    public function ineligibilityReason(): ?string
    {
        $owner = $this->applicationOwner();
        if (! $owner instanceof User) {
            return 'The Applicant account must be active and verified before assistance is available.';
        }

        if (app(AssistedDraftApplicantQuery::class)->eligible($this->sourceApplicationId, includeClosedDrafts: true)->whereKey($owner->id)->exists()) {
            return null;
        }

        return 'Assistance requires an unsubmitted Draft or an open intake without an existing application. The Applicant completes scoped corrections; review submitted work from Admissions.';
    }

    public function isAssistanceContextValid(): bool
    {
        return $this->applicationOwner() instanceof User
            && $this->ineligibilityReason() === null
            && filled(trim((string) ($this->data['assistance_reason'] ?? null)));
    }

    public function isWizardVisible(): bool
    {
        return $this->isAssistanceContextValid();
    }

    public function draftWorkIsAvailable(): bool
    {
        if (! $this->isAssistanceContextValid()) {
            return false;
        }

        return parent::draftWorkIsAvailable();
    }

    protected function validateAssistanceContext(): bool
    {
        if (! $this->isAssistanceContextValid()) {
            $ineligibility = $this->ineligibilityReason();
            if (blank(trim((string) ($this->data['assistance_reason'] ?? null)))) {
                $this->addError('data.assistance_reason', 'Provide a reason for Registrar assistance before saving.');
            }
            Notification::make()
                ->title($ineligibility ? 'Applicant unavailable for assistance' : 'Assistance reason required')
                ->body($ineligibility ?? 'Provide a reason for Registrar assistance before saving.')
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    public function saveDraft(): void
    {
        if (! $this->validateAssistanceContext()) {
            return;
        }

        parent::saveDraft();
    }

    public function saveAndExit(): void
    {
        if (! $this->validateAssistanceContext()) {
            return;
        }

        if ($this->persistDraft()) {
            $this->redirect($this->afterSaveAndExitUrl());
        }
    }

    /** @return array<int, mixed> */
    protected function assistanceComponents(): array
    {
        return [
            Section::make('Assisted draft preparation')
                ->description('The Applicant remains the owner. The Registrar may prepare or discard an unsubmitted Draft only; the Applicant must review declarations and submit it from their own workspace.')
                ->schema([
                    Placeholder::make('assisted_applicant')
                        ->label('Applicant owner')
                        ->content(function (): string {
                            $owner = $this->applicationOwner();

                            return $owner instanceof User
                                ? ($owner->name ? "{$owner->name} ({$owner->email})" : $owner->email)
                                : 'Applicant unavailable';
                        }),
                    Textarea::make('assistance_reason')
                        ->label('Reason for assistance')
                        ->placeholder('e.g. In-person desk intake at Admissions Office due to lack of stable home internet.')
                        ->helperText('Explain why Registrar assistance is needed to prepare this Draft. Required before application sections can be edited or saved.')
                        ->required()
                        ->live(onBlur: true)
                        ->maxLength(1000),
                    Section::make('Supporting references (optional)')
                        ->description('Existing office or authority references may be recorded here as optional supporting details.')
                        ->collapsible()
                        ->collapsed()
                        ->compact()
                        ->schema([
                            TextInput::make('assistance_authority_reference')
                                ->label('Authority reference')
                                ->placeholder('e.g. ADM-REQ-2026-089')
                                ->helperText('Optional reference to an existing office directive, ticket, or authorization.')
                                ->maxLength(255),
                            TextInput::make('assistance_evidence_reference')
                                ->label('Office evidence reference')
                                ->placeholder('e.g. Physical intake form #2026-A12')
                                ->helperText('Optional reference to retained office-held documents. Do not enter private document content.')
                                ->maxLength(255),
                        ]),
                ])
                ->columns(1)
                ->columnSpanFull(),
        ];
    }

    /** @return array{reason: string|null, authority_reference: string|null, evidence_reference: string|null} */
    protected function assistanceData(): array
    {
        return [
            'reason' => filled($this->data['assistance_reason'] ?? null)
                ? trim((string) $this->data['assistance_reason'])
                : null,
            'authority_reference' => filled($this->data['assistance_authority_reference'] ?? null)
                ? trim((string) $this->data['assistance_authority_reference'])
                : null,
            'evidence_reference' => filled($this->data['assistance_evidence_reference'] ?? null)
                ? trim((string) $this->data['assistance_evidence_reference'])
                : null,
        ];
    }

    protected function initialState(): array
    {
        return [
            ...parent::initialState(),
            'email' => $this->applicationOwner()?->email,
            'assistance_reason' => null,
            'assistance_authority_reference' => null,
            'assistance_evidence_reference' => null,
        ];
    }

    public function submissionIsAvailable(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    protected function continuationUrlParameters(): array
    {
        return ['applicant' => $this->applicantId];
    }

    protected function afterSaveAndExitUrl(): string
    {
        return AdmissionApplicationResource::getUrl('index');
    }

    protected function afterDiscardUrl(): string
    {
        return AdmissionApplicationResource::getUrl('index');
    }

    public function failureRecoveryLocation(): string
    {
        return 'Admissions';
    }

    protected function nonDraftRedirectUrl(AdmissionApplication $source): string
    {
        return AdmissionApplicationResource::getUrl('view', ['record' => $source]);
    }
}
