<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class SystemSetting extends Model
{
    public const OperationalStatusDormant = 'Dormant';

    public const OperationalStatusOperational = 'Operational';

    public const OperationalStatusSuperseded = 'Superseded';

    public const ValueTypeBoolean = 'boolean';

    public const ValueTypeDatetime = 'datetime';

    public const ValueTypeJson = 'json';

    public const ValueTypeText = 'text';

    public const SettingDefinitions = [
        'maintenance_mode' => [
            'label' => 'Maintenance Mode',
            'category' => 'Maintenance',
            'description' => 'Historical registry value for a planned application maintenance workflow.',
            'value_type' => self::ValueTypeBoolean,
            'editable' => false,
            'default' => 'false',
            'helper' => 'Read-only historical metadata. It does not place this Laravel application into maintenance mode.',
            'operational_status' => self::OperationalStatusDormant,
            'owner' => 'Deployment operator',
            'runtime_consumer' => 'No application runtime consumer; Laravel maintenance is controlled through deployment or CLI operations.',
        ],
        'maintenance_message' => [
            'label' => 'Maintenance Message',
            'category' => 'Maintenance',
            'description' => 'Historical registry value for a planned maintenance-message workflow.',
            'value_type' => self::ValueTypeText,
            'editable' => false,
            'default' => null,
            'helper' => 'Read-only historical metadata. The running application does not read this value.',
            'operational_status' => self::OperationalStatusDormant,
            'owner' => 'Deployment operator',
            'runtime_consumer' => 'No application runtime consumer; maintenance messaging remains deployment-managed.',
        ],
        'maintenance_eta' => [
            'label' => 'Estimated Maintenance Completion',
            'category' => 'Maintenance',
            'description' => 'Historical registry value for a planned maintenance completion estimate.',
            'value_type' => self::ValueTypeDatetime,
            'editable' => false,
            'default' => null,
            'helper' => 'Read-only historical metadata. The running application does not read this value.',
            'operational_status' => self::OperationalStatusDormant,
            'owner' => 'Deployment operator',
            'runtime_consumer' => 'No application runtime consumer; maintenance timing remains deployment-managed.',
        ],
        'admission_requirements' => [
            'label' => 'Admission Requirements',
            'category' => 'Admissions',
            'description' => 'Superseded generic registry value retained for historical traceability.',
            'value_type' => self::ValueTypeJson,
            'editable' => false,
            'default' => '{"version":"1.0","items":[]}',
            'helper' => 'AdmissionRequirementPolicy records are the operational, configurable source.',
            'operational_status' => self::OperationalStatusSuperseded,
            'owner' => 'Registrar',
            'runtime_consumer' => 'No generic-setting consumer; AdmissionRequirementPolicy drives applicant and Registrar requirement workflows.',
        ],
        'installment_policy_defaults' => [
            'label' => 'Installment Policy Defaults',
            'category' => 'Accounting',
            'description' => 'Dormant fallback definition retained for a future installment-policy rollout.',
            'value_type' => self::ValueTypeJson,
            'editable' => false,
            'default' => '{"version":"1.0","max_months":10,"due_day_rule":"end_of_month","grace_days":3,"penalty_rate":"5.00","penalty_frequency":"per_missed_month","allow_partial_payments":false,"promissory_is_non_clearing":true}',
            'helper' => 'Read-only historical metadata. No current payment workflow reads this value.',
            'operational_status' => self::OperationalStatusDormant,
            'owner' => 'Accounting',
            'runtime_consumer' => 'No verified runtime consumer in the current application.',
        ],
        'college_cutover_effective_term' => [
            'label' => 'College Calendar Cutover Term',
            'category' => 'Academic Calendar',
            'description' => 'Historical rollout marker retained for calendar-migration traceability.',
            'value_type' => self::ValueTypeText,
            'editable' => false,
            'default' => null,
            'helper' => 'Read-only historical metadata. Current academic workflows do not read this value.',
            'operational_status' => self::OperationalStatusDormant,
            'owner' => 'Academic Head and Registrar',
            'runtime_consumer' => 'No verified runtime consumer in the current application.',
        ],
        'college_cutover_effective_datetime' => [
            'label' => 'College Calendar Cutover Date/Time',
            'category' => 'Academic Calendar',
            'description' => 'Historical rollout timestamp retained for calendar-migration traceability.',
            'value_type' => self::ValueTypeDatetime,
            'editable' => false,
            'default' => null,
            'helper' => 'Read-only historical metadata. Current academic workflows do not read this value.',
            'operational_status' => self::OperationalStatusDormant,
            'owner' => 'Academic Head and Registrar',
            'runtime_consumer' => 'No verified runtime consumer in the current application.',
        ],
        'student_unit_load_policy_defaults' => [
            'label' => 'Student Unit Load Policy Defaults',
            'category' => 'Enrollment',
            'description' => 'Seeded fallback defaults for student unit-load policy, separate from faculty load settings.',
            'value_type' => self::ValueTypeJson,
            'editable' => false,
            'default' => '{"version":"1.0","fallback_normal_max_units":21,"regular_overload_excess_cap":6,"summer_overload_excess_cap":6,"default_approving_authority":"Academic Head","default_recording_office":"Registrar"}',
            'helper' => 'Read-only in this generic screen. Student overload decisions are recorded as scoped Student Unit Load Exceptions.',
            'operational_status' => self::OperationalStatusOperational,
            'owner' => 'Academic Head and Registrar',
            'runtime_consumer' => 'StudentUnitLoadPolicy reads the active JSON fallback when evaluating enrollment unit limits.',
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

    public static function operationalStatusFor(?string $key): string
    {
        return (string) Arr::get(self::definitionFor($key), 'operational_status', 'Undocumented');
    }

    public static function ownerFor(?string $key): string
    {
        return (string) Arr::get(self::definitionFor($key), 'owner', 'Unassigned');
    }

    public static function runtimeConsumerFor(?string $key): string
    {
        return (string) Arr::get(
            self::definitionFor($key),
            'runtime_consumer',
            'No verified runtime consumer is documented.',
        );
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
        if ($this->value === '') {
            return 'Not configured';
        }

        if (self::valueTypeFor($this->key) === self::ValueTypeBoolean) {
            return $this->value === 'true' ? 'Enabled' : 'Disabled';
        }

        return $this->value;
    }
}
