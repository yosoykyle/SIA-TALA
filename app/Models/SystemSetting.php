<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class SystemSetting extends Model
{
    public const ValueTypeBoolean = 'boolean';

    public const ValueTypeDatetime = 'datetime';

    public const ValueTypeJson = 'json';

    public const ValueTypeText = 'text';

    public const SettingDefinitions = [
        'maintenance_mode' => [
            'label' => 'Maintenance Mode',
            'category' => 'Maintenance',
            'description' => 'Application-level maintenance flag used by the maintenance service contract.',
            'value_type' => self::ValueTypeBoolean,
            'editable' => true,
            'default' => 'false',
            'helper' => 'Turn on only when staff/public access should be blocked by application maintenance mode.',
        ],
        'maintenance_message' => [
            'label' => 'Maintenance Message',
            'category' => 'Maintenance',
            'description' => 'Optional message shown to users while application-level maintenance is active.',
            'value_type' => self::ValueTypeText,
            'editable' => true,
            'default' => null,
            'helper' => 'Plain text only. Leave blank to use the default maintenance message.',
        ],
        'maintenance_eta' => [
            'label' => 'Estimated Maintenance Completion',
            'category' => 'Maintenance',
            'description' => 'Optional ETA displayed on the application maintenance page.',
            'value_type' => self::ValueTypeDatetime,
            'editable' => true,
            'default' => null,
            'helper' => 'Optional. Use Asia/Manila operational time when entering local maintenance windows.',
        ],
        'admission_requirements' => [
            'label' => 'Admission Requirements',
            'category' => 'Admissions',
            'description' => 'Versioned JSON object for public admission checklist sections.',
            'value_type' => self::ValueTypeJson,
            'editable' => false,
            'default' => '{"version":"1.0","items":[]}',
            'helper' => 'Internal seeded JSON only for this phase. Build a typed Admission Requirements workflow before exposing this to admins.',
        ],
        'installment_policy_defaults' => [
            'label' => 'Installment Policy Defaults',
            'category' => 'Accounting',
            'description' => 'Seeded fallback defaults for the installment policy rollout. Dedicated installment policy records are the operational source once configured.',
            'value_type' => self::ValueTypeJson,
            'editable' => false,
            'default' => '{"version":"1.0","max_months":10,"due_day_rule":"end_of_month","grace_days":3,"penalty_rate":"5.00","penalty_frequency":"per_missed_month","allow_partial_payments":false,"promissory_is_non_clearing":true}',
            'helper' => 'Read-only in this generic screen. Manage actual installment behavior through Accounting installment policy records.',
        ],
        'shs_cutover_effective_term' => [
            'label' => 'SHS Calendar Cutover Term',
            'category' => 'Academic Calendar',
            'description' => 'First SHS term where the new calendar model becomes enforceable.',
            'value_type' => self::ValueTypeText,
            'editable' => false,
            'default' => null,
            'helper' => 'Read-only here. Configure through the academic calendar rollout workflow when enabled.',
        ],
        'shs_cutover_effective_datetime' => [
            'label' => 'SHS Calendar Cutover Date/Time',
            'category' => 'Academic Calendar',
            'description' => 'Runtime date/time when SHS calendar cutover becomes enforceable.',
            'value_type' => self::ValueTypeDatetime,
            'editable' => false,
            'default' => null,
            'helper' => 'Read-only here. Configure through the academic calendar rollout workflow when enabled.',
        ],
        'college_cutover_effective_term' => [
            'label' => 'College Calendar Cutover Term',
            'category' => 'Academic Calendar',
            'description' => 'First College term where the new calendar model becomes enforceable.',
            'value_type' => self::ValueTypeText,
            'editable' => false,
            'default' => null,
            'helper' => 'Read-only here. Configure through the academic calendar rollout workflow when enabled.',
        ],
        'college_cutover_effective_datetime' => [
            'label' => 'College Calendar Cutover Date/Time',
            'category' => 'Academic Calendar',
            'description' => 'Runtime date/time when College calendar cutover becomes enforceable.',
            'value_type' => self::ValueTypeDatetime,
            'editable' => false,
            'default' => null,
            'helper' => 'Read-only here. Configure through the academic calendar rollout workflow when enabled.',
        ],
    ];

    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    public static function editableKeyOptions(): array
    {
        return collect(self::SettingDefinitions)
            ->filter(fn (array $setting): bool => (bool) $setting['editable'])
            ->mapWithKeys(fn (array $setting, string $key): array => [$key => (string) $setting['label']])
            ->all();
    }

    public static function descriptionFor(?string $key): string
    {
        return (string) Arr::get(self::definitionFor($key), 'description', 'This setting is not documented for UI editing.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definitionFor(?string $key): ?array
    {
        if ($key === null || ! array_key_exists($key, self::SettingDefinitions)) {
            return null;
        }

        return self::SettingDefinitions[$key];
    }

    public static function labelFor(?string $key): string
    {
        return (string) Arr::get(self::definitionFor($key), 'label', $key ?? 'Unknown setting');
    }

    public static function categoryFor(?string $key): string
    {
        return (string) Arr::get(self::definitionFor($key), 'category', 'Undocumented');
    }

    public static function helperFor(?string $key): string
    {
        return (string) Arr::get(self::definitionFor($key), 'helper', 'This setting is not documented for UI editing.');
    }

    public static function valueTypeFor(?string $key): string
    {
        return (string) Arr::get(self::definitionFor($key), 'value_type', self::ValueTypeText);
    }

    public static function defaultValueFor(?string $key): ?string
    {
        $value = Arr::get(self::definitionFor($key), 'default');

        return $value === null ? null : (string) $value;
    }

    public static function isEditableKey(?string $key): bool
    {
        return (bool) Arr::get(self::definitionFor($key), 'editable', false);
    }

    public function isEditable(): bool
    {
        return self::isEditableKey($this->key);
    }

    public function formattedValue(): string
    {
        if ($this->value === null || $this->value === '') {
            return 'Not configured';
        }

        if (self::valueTypeFor($this->key) === self::ValueTypeBoolean) {
            return $this->value === 'true' ? 'Enabled' : 'Disabled';
        }

        return $this->value;
    }
}
