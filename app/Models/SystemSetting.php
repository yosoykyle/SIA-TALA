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
        'transcript_signatory' => [
            'label' => 'Printed TOR signatory', 'category' => 'Official outputs',
            'description' => 'School-confirmed name and title printed on new TOR documents.',
            'value_type' => self::ValueTypeJson, 'editable' => false, 'default' => null,
            'helper' => 'Set by the Registrar inside Completion & TOR. Issued versions retain their original certification.',
            'operational_status' => self::OperationalStatusOperational, 'owner' => 'Registrar',
            'runtime_consumer' => 'TranscriptSignatory supplies versioned certification inputs to new TOR previews and issuance.',
        ],
        'payment_channels' => [
            'label' => 'Approved payment channels',
            'category' => 'Finance & tuition',
            'description' => 'School-approved payment instructions shown to students and applicants for manual or onsite tuition payments.',
            'value_type' => self::ValueTypeJson,
            'editable' => false,
            'default' => null,
            'helper' => 'Managed by Accounting. Updates keep versioned history and immediately govern student and applicant payment instructions.',
            'operational_status' => self::OperationalStatusOperational,
            'owner' => 'Accounting',
            'runtime_consumer' => 'SchoolPaymentChannels supplies active bank transfer, e-wallet, and campus cashier instructions to student and applicant payment flows.',
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
