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
