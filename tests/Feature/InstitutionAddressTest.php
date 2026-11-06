<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstitutionAddressTest extends TestCase
{
    public function test_landing_and_official_outputs_share_the_default_address_and_respect_environment_overrides(): void
    {
        $key = 'INSTITUTION_ADDRESS';
        $originalEnvironment = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        try {
            foreach ([
                [null, '162 R. Magsaysay Ave, San Pedro, Laguna, Philippines'],
                ['Synthetic campus, Philippines', 'Synthetic campus, Philippines'],
                ['', ''],
            ] as [$environmentAddress, $expectedAddress]) {
                unset($_ENV[$key], $_SERVER[$key]);
                if ($environmentAddress !== null) {
                    $_ENV[$key] = $_SERVER[$key] = $environmentAddress;
                }
                config(['institution' => require config_path('institution.php')]);

                $landing = $this->get('/');
                $output = $this->blade('<x-official-output-layout title="Address verification">Test output</x-official-output-layout>');

                $this->assertSame($expectedAddress, config('institution.address'));
                $landing->assertSee('<address class="institution-address mb-0">'.e($expectedAddress).'</address>', false);
                if ($expectedAddress === '') {
                    $output->assertDontSee('class="official-output-meta"', false);
                } else {
                    $output->assertSee($expectedAddress);
                }
            }
        } finally {
            [$environmentValue, $serverValue, $processValue] = $originalEnvironment;
            unset($_ENV[$key], $_SERVER[$key]);
            if ($environmentValue !== null) {
                $_ENV[$key] = $environmentValue;
            }
            if ($serverValue !== null) {
                $_SERVER[$key] = $serverValue;
            }
            putenv($processValue === false ? $key : "{$key}={$processValue}");
        }
    }
}
