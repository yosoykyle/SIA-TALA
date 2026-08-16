<?php

namespace App\Actions\Applicants;

class ApplicantEntryReadinessService
{
    public function __construct(
        private AdmissionWindowService $admissionWindowService,
    ) {}

    public function admissionsAreOpen(): bool
    {
        return $this->admissionWindowService->hasOpenAdmissionsWindow();
    }

    public function registrationIsAvailable(): bool
    {
        return $this->admissionsAreOpen();
    }

    /** @return array{code:string,label:string,term:?string,opens_at:?string,closes_at:?string,programs:list<string>,paths:list<string>,is_open:bool}|null */
    public function cycleProjection(): ?array
    {
        $cycle = $this->admissionWindowService->currentCycle();
        $isOpen = $cycle !== null;
        $cycle ??= $this->admissionWindowService->nextPublishedCycle();

        if ($cycle === null) {
            return null;
        }

        $paths = collect([
            $cycle->programs()->wherePivot('accepts_first_year', true)->exists() ? 'First year' : null,
            $cycle->programs()->wherePivot('accepts_transferee', true)->exists() ? 'Transferee' : null,
        ])->filter()->values()->all();

        return [
            'code' => $cycle->code,
            'label' => $cycle->label,
            'term' => $cycle->term?->label,
            'opens_at' => $cycle->opens_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A'),
            'closes_at' => $cycle->closes_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A'),
            'programs' => $cycle->programs->pluck('name')->values()->all(),
            'paths' => $paths,
            'is_open' => $isOpen,
        ];
    }

    /**
     * @return array{support: ?string, support_phone: string, support_phone_uri: string, privacy: string, accessibility: string, map: ?string}
     */
    public function officialReferences(): array
    {
        return [
            'support' => $this->validatedHttpsReference('support_facebook_url'),
            'support_phone' => (string) config('institution.public.support_phone'),
            'support_phone_uri' => (string) config('institution.public.support_phone_uri'),
            'privacy' => route('home', ['modal' => 'privacy']),
            'accessibility' => route('home', ['modal' => 'accessibility']),
            'map' => $this->validatedHttpsReference('map_url'),
        ];
    }

    private function validatedHttpsReference(string $key): ?string
    {
        $reference = config("institution.public.{$key}");

        if (! is_string($reference) || blank($reference)) {
            return null;
        }

        return parse_url($reference, PHP_URL_SCHEME) === 'https' ? $reference : null;
    }
}
