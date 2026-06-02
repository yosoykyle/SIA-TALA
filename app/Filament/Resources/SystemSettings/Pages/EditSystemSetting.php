<?php

namespace App\Filament\Resources\SystemSettings\Pages;

use App\Filament\Resources\SystemSettings\SystemSettingResource;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EditSystemSetting extends EditRecord
{
    protected static string $resource = SystemSettingResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $key = (string) $data['key'];

        return array_merge($data, [
            'setting_key' => $key,
            'setting_label' => SystemSetting::labelFor($key),
            'category_label' => SystemSetting::categoryFor($key),
            'setting_description' => SystemSetting::descriptionFor($key),
            'value_type' => SystemSetting::valueTypeFor($key),
            'is_editable' => SystemSetting::isEditableKey($key),
            'boolean_value' => ($data['value'] ?? 'false') === 'true',
            'datetime_value' => $data['value'] ?? null,
            'text_value' => $data['value'] ?? null,
            'json_value' => $data['value'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var SystemSetting $record */
        $record = $this->record;

        $value = match (SystemSetting::valueTypeFor($record->key)) {
            SystemSetting::ValueTypeBoolean => ($data['boolean_value'] ?? false) ? 'true' : 'false',
            SystemSetting::ValueTypeDatetime => $this->normalizeDatetimeValue($data['datetime_value'] ?? null),
            SystemSetting::ValueTypeJson => $this->validatedJsonValue($data['json_value'] ?? null),
            default => filled($data['text_value'] ?? null) ? (string) $data['text_value'] : null,
        };

        return [
            'value' => $value,
        ];
    }

    private function normalizeDatetimeValue(mixed $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        return CarbonImmutable::parse($value, config('app.timezone'))->toIso8601String();
    }

    /**
     * @throws ValidationException
     */
    private function validatedJsonValue(mixed $value): string
    {
        $data = ['json_value' => $value];

        Validator::make($data, [
            'json_value' => ['required', 'json'],
        ])->validate();

        return (string) $value;
    }
}
