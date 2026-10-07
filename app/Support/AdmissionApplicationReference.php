<?php

namespace App\Support;

use App\Models\AdmissionApplication;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdmissionApplicationReference
{
    public static function generate(int $year): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $suffix = strtr(Str::upper(Str::random(12)), [
                'I' => 'J', 'L' => 'M', 'O' => 'P', '0' => '2', '1' => '3',
            ]);
            $reference = 'APP-'.$year.'-'.implode('-', str_split($suffix, 4));

            if (! AdmissionApplication::query()->where('application_reference', $reference)->exists()) {
                return $reference;
            }
        }

        throw ValidationException::withMessages([
            'application_reference' => 'A reference could not be assigned. Your draft remains saved; please try submitting again.',
        ]);
    }
}
