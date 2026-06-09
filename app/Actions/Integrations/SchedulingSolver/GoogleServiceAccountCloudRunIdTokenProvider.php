<?php

namespace App\Actions\Integrations\SchedulingSolver;

use Google\Auth\Credentials\ServiceAccountCredentials;
use RuntimeException;
use Throwable;

class GoogleServiceAccountCloudRunIdTokenProvider implements CloudRunIdTokenProvider
{
    public function __construct(private readonly ?string $credentialsPath) {}

    public function tokenFor(string $audience): string
    {
        $audience = trim($audience);

        if ($audience === '') {
            throw new RuntimeException('Scheduling solver audience is not configured.');
        }

        $credentialsPath = $this->credentialsPath();

        try {
            $tokenPayload = (new ServiceAccountCredentials(
                scope: null,
                jsonKey: $credentialsPath,
                sub: null,
                targetAudience: $audience,
            ))->fetchAuthToken();
        } catch (Throwable $exception) {
            throw new RuntimeException('Scheduling solver identity token could not be minted.', 0, $exception);
        }

        $idToken = $tokenPayload['id_token'] ?? null;

        if ($idToken === null || trim((string) $idToken) === '') {
            throw new RuntimeException('Scheduling solver identity token response did not include an ID token.');
        }

        return (string) $idToken;
    }

    private function credentialsPath(): string
    {
        $credentialsPath = trim((string) $this->credentialsPath);

        if ($credentialsPath === '') {
            throw new RuntimeException('Scheduling solver credentials path is not configured.');
        }

        if (! is_readable($credentialsPath)) {
            throw new RuntimeException('Scheduling solver credentials file is not readable.');
        }

        return $credentialsPath;
    }
}
