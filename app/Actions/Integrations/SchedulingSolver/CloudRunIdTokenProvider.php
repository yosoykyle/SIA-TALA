<?php

namespace App\Actions\Integrations\SchedulingSolver;

interface CloudRunIdTokenProvider
{
    public function tokenFor(string $audience): string;
}
