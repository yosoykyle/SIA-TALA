<?php

namespace App\Actions\Enrollment;

use App\Models\SystemSetting;
use App\Models\Term;

class StudentUnitLoadPolicy
{
    public const SettingKey = 'student_unit_load_policy_defaults';

    public const DefaultFallbackNormalMaxUnits = 21.0;

    public const DefaultRegularOverloadExcessCap = 6.0;

    public const DefaultSummerOverloadExcessCap = 6.0;

    public const DefaultApprovingAuthority = 'Academic Head';

    public const DefaultRecordingOffice = 'Registrar';

    public function fallbackNormalMaxUnits(): float
    {
        return $this->positiveFloat('fallback_normal_max_units', self::DefaultFallbackNormalMaxUnits);
    }

    public function overloadExcessCapFor(?Term $term): float
    {
        if ($term instanceof Term && $term->type === Term::TypeSummer) {
            return $this->positiveFloat('summer_overload_excess_cap', self::DefaultSummerOverloadExcessCap);
        }

        return $this->positiveFloat('regular_overload_excess_cap', self::DefaultRegularOverloadExcessCap);
    }

    public function configuredCapFor(float $normalLoad, ?Term $term): float
    {
        return $normalLoad + $this->overloadExcessCapFor($term);
    }

    public function defaultApprovingAuthority(): string
    {
        return $this->nonBlankString('default_approving_authority', self::DefaultApprovingAuthority);
    }

    public function defaultRecordingOffice(): string
    {
        return $this->nonBlankString('default_recording_office', self::DefaultRecordingOffice);
    }

    private function positiveFloat(string $key, float $fallback): float
    {
        $value = (float) data_get($this->settings(), $key, $fallback);

        return $value > 0 ? $value : $fallback;
    }

    private function nonBlankString(string $key, string $fallback): string
    {
        $value = (string) data_get($this->settings(), $key, $fallback);

        return filled($value) ? $value : $fallback;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $value = SystemSetting::query()
            ->where('key', self::SettingKey)
            ->orderByDesc('version')
            ->value('value') ?? SystemSetting::defaultValueFor(self::SettingKey);

        if (! is_string($value) || blank($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
