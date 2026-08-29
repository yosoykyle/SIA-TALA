<?php

namespace App\Filament\Pages;

use App\Filament\Applicant\Pages\Application as ApplicantApplication;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;

class AssistedAdmissionApplication extends ApplicantApplication
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'admissions/assisted-entry';

    protected string $view = 'filament.pages.assisted-admission-application';

    public int $applicantId;

    public function mount(): void
    {
        $this->applicantId = request()->integer('applicant');
        abort_unless($this->applicationOwner() instanceof User, 404);

        parent::mount();
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

    /** @return array<int, mixed> */
    protected function assistanceComponents(): array
    {
        return [
            Section::make('Bounded assisted entry')
                ->description('The Applicant remains the owner. The Registrar may prepare or discard an unsubmitted Draft only; the Applicant must review declarations and submit it from their own workspace.')
                ->schema([
                    Placeholder::make('assisted_applicant')
                        ->label('Applicant owner')
                        ->content(function (): string {
                            $owner = $this->applicationOwner();

                            return $owner instanceof User ? $owner->email : 'Applicant unavailable';
                        }),
                    Textarea::make('assistance_reason')
                        ->label('Reason for assistance')
                        ->required()
                        ->maxLength(1000),
                    TextInput::make('assistance_authority_reference')
                        ->label('Authority reference')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('assistance_evidence_reference')
                        ->label('Retained evidence reference')
                        ->helperText('Reference the retained office evidence; do not place private evidence content in this field.')
                        ->required()
                        ->maxLength(255),
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
                ? (string) $this->data['assistance_reason']
                : null,
            'authority_reference' => filled($this->data['assistance_authority_reference'] ?? null)
                ? (string) $this->data['assistance_authority_reference']
                : null,
            'evidence_reference' => filled($this->data['assistance_evidence_reference'] ?? null)
                ? (string) $this->data['assistance_evidence_reference']
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

    protected function afterDiscardUrl(): string
    {
        return AdmissionApplicationResource::getUrl();
    }
}
