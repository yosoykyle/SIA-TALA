<?php

namespace App\Actions\Completion;

use App\Models\SystemSetting;
use App\Models\TranscriptRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class TranscriptSignatory
{
    public const SettingKey = 'transcript_signatory';

    /** @return array{name: ?string, title: ?string, source_version: ?int} */
    public function current(): array
    {
        $setting = SystemSetting::query()->where('key', self::SettingKey)
            ->where('scope_type', 'institution')->where('scope_id', 0)
            ->where('status', 'Active')->where('effective_from', '<=', now())
            ->orderByDesc('version')->first();
        $value = $setting ? json_decode($setting->value, true, flags: JSON_THROW_ON_ERROR) : [];

        return ['name' => $value['name'] ?? null, 'title' => $value['title'] ?? null, 'source_version' => $setting ? (int) $setting->version : null];
    }

    public function save(User $actor, string $name, string $title): void
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleRegistrar)) {
            throw new AuthorizationException('Only Registrar staff may set the printed TOR signatory.');
        }
        $value = validator(['name' => trim($name), 'title' => trim($title)], [
            'name' => ['required', 'string', 'max:255'], 'title' => ['required', 'string', 'max:255'],
        ])->validate();
        DB::transaction(function () use ($actor, $value): void {
            $previous = SystemSetting::query()->where('key', self::SettingKey)
                ->where('scope_type', 'institution')->where('scope_id', 0)->orderByDesc('version')->lockForUpdate()->first();
            if ($previous && json_decode($previous->value, true, flags: JSON_THROW_ON_ERROR) === $value) {
                return;
            }
            SystemSetting::query()->where('key', self::SettingKey)->where('scope_type', 'institution')
                ->where('scope_id', 0)->where('status', 'Active')->update(['status' => 'Superseded', 'effective_until' => now()]);
            (new SystemSetting)->forceFill([
                'key' => self::SettingKey, 'scope_type' => 'institution', 'scope_id' => 0,
                'value_type' => SystemSetting::ValueTypeJson, 'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'effective_from' => now(), 'version' => (int) ($previous?->version ?? 0) + 1,
                'status' => 'Active', 'changed_by' => $actor->id,
                'reason' => 'Registrar updated the name and title printed on future TOR documents.',
            ])->save();
        }, attempts: 3);
    }

    /** @return array{name: ?string, title: ?string, source_version: ?int} */
    public function forRequest(TranscriptRequest $request): array
    {
        $current = $request->template_version === TranscriptRequest::TemplateServitechV2 ? $this->current() : [];

        return [
            'name' => $current['name'] ?? $request->signatory_name,
            'title' => $current['title'] ?? $request->signatory_title,
            'source_version' => $current['source_version'] ?? null,
        ];
    }
}
