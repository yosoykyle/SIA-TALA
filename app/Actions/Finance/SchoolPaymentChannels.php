<?php

namespace App\Actions\Finance;

use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SchoolPaymentChannels
{
    public const SettingKey = 'payment_channels';

    /**
     * Top Philippine HEI depository banks for datalist suggestions.
     *
     * @return list<string>
     */
    public static function bankPresets(): array
    {
        return [
            'Land Bank of the Philippines',
            'BDO Unibank',
            'Bank of the Philippine Islands (BPI)',
            'Metrobank',
            'UnionBank',
            'Security Bank',
            'Philippine National Bank (PNB)',
            'RCBC',
            'Chinabank',
            'Development Bank of the Philippines (DBP)',
        ];
    }

    /**
     * Philippine e-wallet providers for datalist suggestions.
     *
     * @return list<string>
     */
    public static function eWalletPresets(): array
    {
        return [
            'GCash',
            'Maya',
            'GoTyme Bank',
            'GrabPay',
            'ShopeePay',
            'PalawanPay',
            'Coins.ph',
            'Other e-wallet',
        ];
    }

    public static function bankNumberHint(?string $bankName): string
    {
        return 'Use the deposit account number shown by the bank: 10–16 digits. Spaces and hyphens are accepted.';
    }

    public static function eWalletNumberHint(?string $provider): string
    {
        $normalized = strtolower(trim((string) $provider));

        return match (true) {
            str_contains($normalized, 'maya') => '11-digit PH mobile (09XX XXX XXXX) or 10 to 16 digit Maya account number.',
            str_contains($normalized, 'gotyme') => '10 to 12 digit GoTyme Bank account number or 11-digit PH mobile.',
            in_array($normalized, ['gcash', 'grabpay', 'shopeepay', 'palawanpay'], true) => '11-digit PH mobile number (09XX XXX XXXX or +63 9XX XXX XXXX).',
            default => '11-digit PH mobile number or 6 to 24 character wallet/account ID.',
        };
    }

    /**
     * @return array{label: string, placeholder: string, hint: string}
     */
    public static function referenceGuidance(?string $channelOrGroup, ?string $providerOrBank = null): array
    {
        $groupKey = strtolower(trim((string) $channelOrGroup));
        $providerKey = strtolower(trim((string) $providerOrBank));

        if (str_contains($providerKey, 'gcash') || in_array($groupKey, ['gcash', 'gcash_manual'], true)) {
            return [
                'label' => 'Payment reference (optional)',
                'placeholder' => '13-digit Ref. No. (e.g. 1234567890123)',
                'hint' => 'Enter the 13-digit GCash Ref. No. shown on your receipt.',
            ];
        }

        if (str_contains($providerKey, 'maya')) {
            return [
                'label' => 'Payment reference (optional)',
                'placeholder' => 'Reference ID or InstaPay trace no.',
                'hint' => 'Enter the Maya Reference ID or InstaPay trace number on your receipt.',
            ];
        }

        if (in_array($groupKey, ['e_wallets', 'e_wallet'], true)) {
            return [
                'label' => 'Payment reference (optional)',
                'placeholder' => 'Wallet reference ID or InstaPay trace no.',
                'hint' => 'Enter the e-wallet reference ID or InstaPay trace number on your receipt.',
            ];
        }

        if ($groupKey === 'bank_transfer') {
            return [
                'label' => 'Payment reference (optional)',
                'placeholder' => 'InstaPay/PESONet ref. or deposit slip validation no.',
                'hint' => 'Enter the InstaPay/PESONet reference or validated deposit slip number.',
            ];
        }

        return [
            'label' => 'Payment reference (optional)',
            'placeholder' => 'If shown on your receipt',
            'hint' => 'Enter the transaction or Official Receipt (OR) reference if shown.',
        ];
    }

    /**
     * @return array{
     *     bank_transfer: array{enabled: bool, label: string, accounts: list<array{id: string, bank_name: ?string, branch: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>},
     *     e_wallets: array{enabled: bool, label: string, accounts: list<array{id: string, provider: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>},
     *     cash: array{enabled: bool, label: string, location: ?string, hours: ?string, schedule: array<string, mixed>, instructions: ?string},
     *     source_version: ?int,
     *     updated_at: ?string
     * }
     */
    public function current(): array
    {
        $setting = SystemSetting::query()
            ->where('key', self::SettingKey)
            ->where('scope_type', 'institution')
            ->where('scope_id', 0)
            ->where('status', 'Active')
            ->where('effective_from', '<=', now())
            ->orderByDesc('version')
            ->first();

        $stored = [];

        if ($setting instanceof SystemSetting && is_string($setting->value) && $setting->value !== '') {
            $decoded = json_decode($setting->value, true);

            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }

        $normalized = $this->normalizeChannels($stored);

        return [
            'bank_transfer' => $normalized['bank_transfer'],
            'e_wallets' => $normalized['e_wallets'],
            'cash' => $normalized['cash'],
            'source_version' => $setting instanceof SystemSetting ? (int) $setting->version : null,
            'updated_at' => $setting instanceof SystemSetting ? $setting->updated_at?->toIso8601String() : null,
        ];
    }

    /**
     * @param  ?array<string, mixed>  $channels
     */
    public function hasEnabledDigitalChannels(?array $channels = null): bool
    {
        return $this->activeDigitalAccounts($channels) !== [];
    }

    /**
     * Return active, configured digital destination accounts across Bank Transfer and E-Wallets.
     *
     * @param  ?array<string, mixed>  $channels
     * @return list<array{
     *     group: string,
     *     key: string,
     *     evidence_channel: string,
     *     group_label: string,
     *     id: string,
     *     title: string,
     *     label: string,
     *     bank_name: ?string,
     *     branch: ?string,
     *     provider: ?string,
     *     provider_or_bank: string,
     *     account_name: ?string,
     *     account_number: string,
     *     instructions: ?string,
     *     option_label: string
     * }>
     */
    public function activeDigitalAccounts(?array $channels = null): array
    {
        $resolved = $channels !== null
            ? $this->normalizeChannels($channels)
            : $this->current();

        return $this->buildDigitalDisplayItems($resolved, includeIncomplete: false);
    }

    /**
     * @param  ?array<string, mixed>  $channels
     * @return array<string, string>
     */
    public function digitalMethodOptions(?array $channels = null): array
    {
        $accounts = $this->activeDigitalAccounts($channels);
        $options = [];

        if (collect($accounts)->contains(fn (array $account): bool => $account['group'] === 'bank_transfer')) {
            $options['bank_transfer'] = 'Bank Transfer';
        }

        if (collect($accounts)->contains(fn (array $account): bool => $account['group'] === 'e_wallets')) {
            $options['e_wallets'] = 'E-Wallet';
        }

        return $options;
    }

    /**
     * @param  ?array<string, mixed>  $channels
     * @return array<string, string>
     */
    public function accountOptionsForGroup(string $group, ?array $channels = null): array
    {
        $normalizedGroup = $this->normalizeGroupName($group);

        if ($normalizedGroup === null) {
            return [];
        }

        return collect($this->activeDigitalAccounts($channels))
            ->where('group', $normalizedGroup)
            ->mapWithKeys(fn (array $account): array => [$account['id'] => $account['option_label']])
            ->all();
    }

    /**
     * Resolve an immutable destination-account snapshot for submission or manual confirmation.
     *
     * @param  ?array<string, mixed>  $channels
     * @return array{group: string, account_id: string, provider_or_bank: string, branch: ?string, account_name: ?string, account_number: string}|null
     */
    public function resolveAccountSnapshot(string $group, ?string $accountId = null, ?array $channels = null): ?array
    {
        $matched = $this->matchDigitalAccount($group, $accountId, $channels);

        if ($matched === null) {
            return null;
        }

        return [
            'group' => $matched['group'],
            'account_id' => $matched['id'],
            'provider_or_bank' => $matched['provider_or_bank'],
            'branch' => $matched['branch'],
            'account_name' => $matched['account_name'],
            'account_number' => $matched['account_number'],
        ];
    }

    /**
     * Resolve canonical Payment / PaymentEvidenceVersion channel code for the chosen group/account.
     *
     * @param  ?array<string, mixed>  $channels
     */
    public function resolveEvidenceChannel(string $group, ?string $accountId = null, ?array $channels = null): string
    {
        $normalizedGroup = $this->normalizeGroupName($group);

        if ($normalizedGroup === 'bank_transfer') {
            return 'bank_transfer';
        }

        if ($normalizedGroup === 'e_wallets') {
            $matched = $this->matchDigitalAccount($group, $accountId, $channels);

            if ($matched !== null) {
                return $matched['evidence_channel'];
            }

            return strtolower(trim($group)) === 'gcash_manual' || strtolower(trim($group)) === 'gcash'
                ? 'gcash_manual'
                : 'e_wallet';
        }

        return 'cash';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledChannels(): array
    {
        $current = $this->current();
        $channels = [];

        if ($current['bank_transfer']['enabled'] && $this->groupHasActiveAccounts($current['bank_transfer'])) {
            $channels[] = array_merge($current['bank_transfer'], [
                'key' => 'bank_transfer',
                'evidence_channel' => 'bank_transfer',
            ]);
        }

        if ($current['e_wallets']['enabled'] && $this->groupHasActiveAccounts($current['e_wallets'])) {
            $channels[] = array_merge($current['e_wallets'], [
                'key' => 'e_wallets',
                'evidence_channel' => 'e_wallet',
            ]);
        }

        if ($current['cash']['enabled']) {
            $channels[] = array_merge($current['cash'], [
                'key' => 'cash',
                'evidence_channel' => 'cash',
            ]);
        }

        return $channels;
    }

    /**
     * @param  ?array<string, mixed>  $channelsOverride
     * @return array{
     *     title: string,
     *     electronicChannels: list<array<string, mixed>>,
     *     bankAccounts: list<array<string, mixed>>,
     *     eWalletAccounts: list<array<string, mixed>>,
     *     hasElectronicChannels: bool,
     *     hasEnabledDigitalChannels: bool,
     *     cashChannel: ?array<string, mixed>,
     *     cash: ?array<string, mixed>,
     *     hasCashChannel: bool,
     *     cashFallbackActive: bool,
     *     isOnsiteOnly: bool
     * }
     */
    public function forDisplay(?array $channelsOverride = null, ?CarbonImmutable $now = null): array
    {
        $channels = $channelsOverride !== null
            ? $this->normalizeChannels($channelsOverride)
            : $this->current();

        $hasExplicitOverrideSchedule = $channelsOverride !== null
            && is_array(data_get($channelsOverride, 'cash.schedule'));

        $electronicChannels = $this->buildDigitalDisplayItems(
            $channels,
            includeIncomplete: $channelsOverride !== null,
        );

        $cashEnabled = (bool) ($channels['cash']['enabled'] ?? false);
        $cashFallbackActive = $electronicChannels === [] && ! $cashEnabled;
        $cashChannel = null;

        if ($cashEnabled) {
            $cashChannel = $this->buildCashDisplayChannel(
                cashSource: $channels['cash'],
                isFallback: false,
                deriveHoursFromSchedule: $hasExplicitOverrideSchedule,
                now: $now,
            );
        } elseif ($cashFallbackActive) {
            $cashChannel = $this->buildCashDisplayChannel(
                cashSource: $this->defaultChannels()['cash'],
                isFallback: true,
                deriveHoursFromSchedule: false,
                now: $now,
            );
        }

        return $this->assembleDisplayPayload(
            electronicChannels: $electronicChannels,
            hasEnabledDigitalChannels: $this->hasEnabledDigitalChannels($channels),
            cashChannel: $cashChannel,
            cashFallbackActive: $cashFallbackActive,
        );
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $enabledChannels
     * @return array{
     *     title: string,
     *     electronicChannels: list<array<string, mixed>>,
     *     bankAccounts: list<array<string, mixed>>,
     *     eWalletAccounts: list<array<string, mixed>>,
     *     hasElectronicChannels: bool,
     *     hasEnabledDigitalChannels: bool,
     *     cashChannel: ?array<string, mixed>,
     *     cash: ?array<string, mixed>,
     *     hasCashChannel: bool,
     *     cashFallbackActive: bool,
     *     isOnsiteOnly: bool
     * }
     */
    public function forDisplayFromEnabledList(array $enabledChannels, ?CarbonImmutable $now = null): array
    {
        $electronicChannels = [];
        $cashSource = null;

        foreach ($enabledChannels as $channel) {
            if (! is_array($channel)) {
                continue;
            }

            $key = $channel['key'] ?? null;

            if (in_array($key, ['bank_transfer', 'e_wallets', 'gcash'], true)) {
                if (is_array($channel['accounts'] ?? null)) {
                    $normalizedGroup = $key === 'bank_transfer'
                        ? $this->normalizeChannels(['bank_transfer' => $channel, 'e_wallets' => ['enabled' => false, 'accounts' => []], 'cash' => ['enabled' => false]])
                        : $this->normalizeChannels(['bank_transfer' => ['enabled' => false, 'accounts' => []], $key => $channel, 'cash' => ['enabled' => false]]);

                    foreach ($this->buildDigitalDisplayItems($normalizedGroup, includeIncomplete: false) as $item) {
                        $electronicChannels[] = $item;
                    }
                } else {
                    $groupKey = $key === 'bank_transfer' ? 'bank_transfer' : 'e_wallets';
                    $title = $groupKey === 'bank_transfer'
                        ? ($this->sanitizeNullableString($channel['bank_name'] ?? null) ?? 'Bank Transfer')
                        : ($this->sanitizeNullableString($channel['provider'] ?? null) ?? ($this->sanitizeNullableString($channel['label'] ?? null) ?? 'E-Wallet'));
                    $accountNumber = $this->sanitizeNullableString($channel['account_number'] ?? null);

                    if ($accountNumber !== null) {
                        $electronicChannels[] = [
                            'group' => $groupKey,
                            'key' => $groupKey,
                            'evidence_channel' => $channel['evidence_channel'] ?? ($groupKey === 'bank_transfer' ? 'bank_transfer' : (strtolower($title) === 'gcash' ? 'gcash_manual' : 'e_wallet')),
                            'group_label' => $groupKey === 'bank_transfer' ? 'Bank Transfer' : 'E-Wallets',
                            'id' => $this->sanitizeNullableString($channel['id'] ?? null) ?? ($groupKey === 'bank_transfer' ? 'bank-1' : 'ewallet-1'),
                            'title' => $title,
                            'label' => $title,
                            'bank_name' => $groupKey === 'bank_transfer' ? $title : null,
                            'branch' => $groupKey === 'bank_transfer' ? $this->sanitizeNullableString($channel['branch'] ?? null) : null,
                            'provider' => $groupKey === 'e_wallets' ? $title : null,
                            'provider_or_bank' => $title,
                            'account_name' => $this->sanitizeNullableString($channel['account_name'] ?? null),
                            'account_number' => $accountNumber,
                            'instructions' => $this->sanitizeNullableString($channel['instructions'] ?? null),
                            'option_label' => "{$title} — {$accountNumber}",
                        ];
                    }
                }
            } elseif ($key === 'cash' && $cashSource === null) {
                $cashSource = $channel;
            }
        }

        $cashFallbackActive = $electronicChannels === [] && $cashSource === null;
        $cashChannel = null;

        if ($cashSource !== null) {
            $cashChannel = $this->buildCashDisplayChannel(
                cashSource: $cashSource,
                isFallback: false,
                deriveHoursFromSchedule: false,
                now: $now,
            );
        } elseif ($cashFallbackActive) {
            $cashChannel = $this->buildCashDisplayChannel(
                cashSource: $this->defaultChannels()['cash'],
                isFallback: true,
                deriveHoursFromSchedule: false,
                now: $now,
            );
        }

        return $this->assembleDisplayPayload(
            electronicChannels: $electronicChannels,
            hasEnabledDigitalChannels: collect($electronicChannels)->contains(
                fn (array $item): bool => filled($item['account_number'] ?? null),
            ),
            cashChannel: $cashChannel,
            cashFallbackActive: $cashFallbackActive,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     bank_transfer: array{enabled: bool, label: string, accounts: list<array{id: string, bank_name: ?string, branch: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>},
     *     e_wallets: array{enabled: bool, label: string, accounts: list<array{id: string, provider: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>},
     *     cash: array{enabled: bool, label: string, location: ?string, hours: ?string, schedule: array<string, mixed>, instructions: ?string},
     *     source_version: ?int,
     *     updated_at: ?string
     * }
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(array $payload, User $actor, ?string $reason = null): array
    {
        if (! $actor->canAuthenticate() || ! $actor->hasRole(User::StaffRoleAccounting)) {
            throw new AuthorizationException('Only Accounting staff may configure school payment channels.');
        }

        Validator::make($payload + ['reason' => $reason], self::inputRules())->validate();

        $hasScheduleInPayload = is_array(data_get($payload, 'cash.schedule'));
        $normalized = $this->normalizeChannels($payload);

        $validator = Validator::make($normalized, [
            'bank_transfer.enabled' => ['required', 'boolean'],
            'bank_transfer.label' => ['required', 'string', 'max:100'],
            'bank_transfer.accounts' => ['array', ...($normalized['bank_transfer']['enabled'] ? ['min:1'] : [])],
            'bank_transfer.accounts.*.id' => ['required', 'string', 'max:64', 'distinct:strict'],
            'bank_transfer.accounts.*.bank_name' => ['required_if:bank_transfer.enabled,true', 'nullable', 'string', 'max:120'],
            'bank_transfer.accounts.*.branch' => ['nullable', 'string', 'max:120'],
            'bank_transfer.accounts.*.account_name' => ['required_if:bank_transfer.enabled,true', 'nullable', 'string', 'max:120'],
            'bank_transfer.accounts.*.account_number' => ['required_if:bank_transfer.enabled,true', 'nullable', 'string', 'max:100'],
            'bank_transfer.accounts.*.instructions' => ['nullable', 'string', 'max:500'],
            'e_wallets.enabled' => ['required', 'boolean'],
            'e_wallets.label' => ['required', 'string', 'max:100'],
            'e_wallets.accounts' => ['array', ...($normalized['e_wallets']['enabled'] ? ['min:1'] : [])],
            'e_wallets.accounts.*.id' => ['required', 'string', 'max:64', 'distinct:strict'],
            'e_wallets.accounts.*.provider' => ['required_if:e_wallets.enabled,true', 'nullable', 'string', 'max:120'],
            'e_wallets.accounts.*.account_name' => ['required_if:e_wallets.enabled,true', 'nullable', 'string', 'max:120'],
            'e_wallets.accounts.*.account_number' => ['required_if:e_wallets.enabled,true', 'nullable', 'string', 'max:100'],
            'e_wallets.accounts.*.instructions' => ['nullable', 'string', 'max:500'],
            'cash.enabled' => ['required', 'boolean'],
            'cash.label' => ['required', 'string', 'max:100'],
            'cash.location' => ['required_if:cash.enabled,true', 'nullable', 'string', 'max:160'],
            'cash.hours' => ['nullable', 'string', 'max:160'],
            'cash.instructions' => ['nullable', 'string', 'max:500'],
        ], [
            'bank_transfer.accounts.min' => 'Add at least one bank account when Bank Transfer is enabled.',
            'bank_transfer.accounts.*.bank_name.required_if' => 'Bank name is required when Bank Transfer is enabled.',
            'bank_transfer.accounts.*.account_name.required_if' => 'Account name is required when Bank Transfer is enabled.',
            'bank_transfer.accounts.*.account_number.required_if' => 'Account number is required when Bank Transfer is enabled.',
            'e_wallets.accounts.min' => 'Add at least one e-wallet account when E-Wallets are enabled.',
            'e_wallets.accounts.*.provider.required_if' => 'E-wallet provider is required when E-Wallets are enabled.',
            'e_wallets.accounts.*.account_name.required_if' => 'Account name is required when E-Wallets are enabled.',
            'e_wallets.accounts.*.account_number.required_if' => 'Mobile or account number is required when E-Wallets are enabled.',
            'cash.location.required_if' => 'Cashier location is required when Cash (Campus Cashier) is enabled.',
        ]);

        $errors = $validator->fails() ? $validator->errors()->toArray() : [];

        if ($normalized['bank_transfer']['enabled']) {
            foreach ($normalized['bank_transfer']['accounts'] as $index => $account) {
                $errorKey = "bank_transfer.accounts.{$index}.account_number";
                $accountNumber = $account['account_number'];

                if ($accountNumber !== null && ! isset($errors[$errorKey]) && ! $this->isValidBankAccountNumber($accountNumber)) {
                    $errors[$errorKey][] = 'Enter a valid 10 to 16 digit Philippine bank account number.';
                }
            }
        }

        if ($normalized['e_wallets']['enabled']) {
            foreach ($normalized['e_wallets']['accounts'] as $index => $account) {
                $errorKey = "e_wallets.accounts.{$index}.account_number";
                $accountNumber = $account['account_number'];

                if ($accountNumber !== null && ! isset($errors[$errorKey])) {
                    $formatError = $this->validateEWalletAccountNumber($account['provider'], $accountNumber);

                    if ($formatError !== null) {
                        $errors[$errorKey][] = $formatError;
                    }
                }
            }
        }

        $validatedSchedule = $normalized['cash']['schedule'];

        if ($hasScheduleInPayload) {
            try {
                $validatedSchedule = CashierSchedule::validate($normalized['cash']['schedule']);
            } catch (ValidationException $scheduleException) {
                $errors = array_merge($errors, $scheduleException->errors());
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $validated = $validator->validated();
        $validated['bank_transfer']['accounts'] = array_values($validated['bank_transfer']['accounts'] ?? []);
        $validated['e_wallets']['accounts'] = array_values($validated['e_wallets']['accounts'] ?? []);
        $validated['cash']['schedule'] = $validatedSchedule;

        if ($hasScheduleInPayload) {
            $validated['cash']['hours'] = CashierSchedule::formatHoursSummary($validatedSchedule);
        }

        $encodedValue = json_encode($validated, JSON_THROW_ON_ERROR);
        $cleanReason = $this->sanitizeNullableString($reason)
            ?? 'Accounting updated school payment channel instructions.';

        DB::transaction(function () use ($actor, $validated, $encodedValue, $cleanReason): void {
            $previous = SystemSetting::query()
                ->where('key', self::SettingKey)
                ->where('scope_type', 'institution')
                ->where('scope_id', 0)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($previous instanceof SystemSetting && is_string($previous->value) && $previous->value !== '') {
                $previousValue = json_decode($previous->value, true);

                if (is_array($previousValue) && $this->normalizeChannels($previousValue) === $validated) {
                    return;
                }
            }

            $now = now();

            SystemSetting::query()
                ->where('key', self::SettingKey)
                ->where('scope_type', 'institution')
                ->where('scope_id', 0)
                ->where('status', 'Active')
                ->update([
                    'status' => 'Superseded',
                    'effective_until' => $now,
                ]);

            (new SystemSetting)->forceFill([
                'key' => self::SettingKey,
                'scope_type' => 'institution',
                'scope_id' => 0,
                'value_type' => SystemSetting::ValueTypeJson,
                'value' => $encodedValue,
                'effective_from' => $now,
                'version' => (int) ($previous?->version ?? 0) + 1,
                'status' => 'Active',
                'changed_by' => $actor->id,
                'reason' => $cleanReason,
            ])->save();
        }, attempts: 3);

        return $this->current();
    }

    /** @return array<string, list<string>> */
    public static function inputRules(): array
    {
        $rules = [
            'reason' => ['nullable', 'string', 'max:255'],
            'cash' => ['sometimes', 'array'],
            'cash.enabled' => ['sometimes', 'boolean'],
            'cash.location' => ['nullable', 'string', 'max:160'],
            'cash.hours' => ['nullable', 'string', 'max:160'],
            'cash.instructions' => ['nullable', 'string', 'max:500'],
            'cash.schedule' => ['sometimes', 'array'],
            'cash.schedule.timezone' => ['sometimes', 'string', 'timezone'],
            'cash.schedule.cutoff_note' => ['nullable', 'string', 'max:255'],
            'cash.schedule.weekly' => ['sometimes', 'array'],
            'cash.schedule.weekly.*' => ['array'],
            'cash.schedule.weekly.*.open' => ['sometimes', 'boolean'],
            'cash.schedule.weekly.*.opens_at' => ['nullable', 'string'],
            'cash.schedule.weekly.*.closes_at' => ['nullable', 'string'],
            'cash.schedule.exceptions' => ['sometimes', 'array'],
            'cash.schedule.exceptions.*' => ['array'],
            'cash.schedule.exceptions.*.starts_on' => ['nullable', 'string'],
            'cash.schedule.exceptions.*.ends_on' => ['nullable', 'string'],
            'cash.schedule.exceptions.*.status' => ['sometimes', 'string'],
            'cash.schedule.exceptions.*.opens_at' => ['nullable', 'string'],
            'cash.schedule.exceptions.*.closes_at' => ['nullable', 'string'],
            'cash.schedule.exceptions.*.label' => ['nullable', 'string', 'max:160'],
        ];

        foreach (['bank_transfer', 'e_wallets', 'gcash'] as $group) {
            $rules[$group] = ['sometimes', 'array'];
            $rules["{$group}.enabled"] = ['sometimes', 'boolean'];
            $rules["{$group}.accounts"] = ['sometimes', 'array'];
            $rules["{$group}.accounts.*"] = ['array'];

            foreach (['id' => 64, 'bank_name' => 120, 'provider' => 120, 'branch' => 120, 'account_name' => 120, 'account_number' => 100, 'instructions' => 500] as $field => $limit) {
                $rules["{$group}.accounts.*.{$field}"] = ['nullable', 'string', "max:{$limit}"];
                $rules["{$group}.{$field}"] = ['nullable', 'string', "max:{$limit}"];
            }

            $rules["{$group}.accounts.*.id"][] = 'distinct:strict';
        }

        return $rules;
    }

    /**
     * Normalize stored channels to the v2 shape, preserving legacy records.
     *
     * @param  array<string, mixed>  $input
     * @return array{bank_transfer: array<string, mixed>, e_wallets: array<string, mixed>, cash: array<string, mixed>}
     */
    public function normalizeChannels(array $input): array
    {
        $defaults = $this->defaultChannels();

        return [
            'bank_transfer' => $this->normalizeBankGroup($input, $defaults['bank_transfer']),
            'e_wallets' => $this->normalizeEWalletGroup($input, $defaults['e_wallets']),
            'cash' => $this->normalizeCashGroup($input, $defaults['cash']),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{enabled: bool, label: string, accounts: list<array{id: string, bank_name: ?string, branch: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>}  $defaultGroup
     * @return array{enabled: bool, label: string, accounts: list<array{id: string, bank_name: ?string, branch: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>}
     */
    private function normalizeBankGroup(array $input, array $defaultGroup): array
    {
        if (! array_key_exists('bank_transfer', $input)) {
            return $defaultGroup;
        }

        $bankInput = is_array($input['bank_transfer'] ?? null) ? $input['bank_transfer'] : [];
        $enabled = filter_var($bankInput['enabled'] ?? $defaultGroup['enabled'], FILTER_VALIDATE_BOOLEAN);
        $label = $this->sanitizeNullableString($bankInput['label'] ?? null) ?? $defaultGroup['label'];

        if (array_key_exists('accounts', $bankInput) && is_array($bankInput['accounts'])) {
            $accounts = $this->normalizeBankAccounts($bankInput['accounts']);
        } elseif ($this->hasAnyKey($bankInput, ['bank_name', 'branch', 'account_name', 'account_number', 'instructions'])) {
            $bankName = $this->sanitizeNullableString($bankInput['bank_name'] ?? null);
            $branch = $this->sanitizeNullableString($bankInput['branch'] ?? null);
            $accountName = $this->sanitizeNullableString($bankInput['account_name'] ?? null);
            $accountNumber = $this->sanitizeNullableString($bankInput['account_number'] ?? null);
            $instructions = $this->sanitizeNullableString($bankInput['instructions'] ?? null);

            $accounts = ($enabled || $bankName !== null || $branch !== null || $accountName !== null || $accountNumber !== null || $instructions !== null)
                ? [[
                    'id' => 'legacy-bank-1',
                    'bank_name' => $bankName,
                    'branch' => $branch,
                    'account_name' => $accountName,
                    'account_number' => $accountNumber,
                    'instructions' => $instructions,
                ]]
                : [];
        } else {
            $accounts = [];
        }

        return [
            'enabled' => $enabled,
            'label' => $label,
            'accounts' => $accounts,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{enabled: bool, label: string, accounts: list<array{id: string, provider: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>}  $defaultGroup
     * @return array{enabled: bool, label: string, accounts: list<array{id: string, provider: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>}
     */
    private function normalizeEWalletGroup(array $input, array $defaultGroup): array
    {
        if (array_key_exists('e_wallets', $input)) {
            $walletInput = is_array($input['e_wallets'] ?? null) ? $input['e_wallets'] : [];
            $enabled = filter_var($walletInput['enabled'] ?? $defaultGroup['enabled'], FILTER_VALIDATE_BOOLEAN);
            $label = $this->sanitizeNullableString($walletInput['label'] ?? null) ?? $defaultGroup['label'];
            $accounts = is_array($walletInput['accounts'] ?? null)
                ? $this->normalizeEWalletAccounts($walletInput['accounts'])
                : [];

            return [
                'enabled' => $enabled,
                'label' => $label,
                'accounts' => $accounts,
            ];
        }

        if (array_key_exists('gcash', $input)) {
            $gcashInput = is_array($input['gcash'] ?? null) ? $input['gcash'] : [];
            $enabled = filter_var($gcashInput['enabled'] ?? $defaultGroup['enabled'], FILTER_VALIDATE_BOOLEAN);
            $provider = $this->sanitizeNullableString($gcashInput['provider'] ?? null) ?? 'GCash';
            $accountName = $this->sanitizeNullableString($gcashInput['account_name'] ?? null);
            $accountNumber = $this->sanitizeNullableString($gcashInput['account_number'] ?? null);
            $instructions = $this->sanitizeNullableString($gcashInput['instructions'] ?? null);

            $accounts = ($enabled || $accountName !== null || $accountNumber !== null || $instructions !== null)
                ? [[
                    'id' => 'legacy-gcash-1',
                    'provider' => $provider,
                    'account_name' => $accountName,
                    'account_number' => $accountNumber,
                    'instructions' => $instructions,
                ]]
                : [];

            return [
                'enabled' => $enabled,
                'label' => $defaultGroup['label'],
                'accounts' => $accounts,
            ];
        }

        return $defaultGroup;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{enabled: bool, label: string, location: ?string, hours: ?string, schedule: array<string, mixed>, instructions: ?string}  $defaultGroup
     * @return array{enabled: bool, label: string, location: ?string, hours: ?string, schedule: array<string, mixed>, instructions: ?string}
     */
    private function normalizeCashGroup(array $input, array $defaultGroup): array
    {
        $cashInput = is_array($input['cash'] ?? null) ? $input['cash'] : [];
        $cashSchedule = is_array($cashInput['schedule'] ?? null)
            ? CashierSchedule::normalize($cashInput['schedule'])
            : $defaultGroup['schedule'];

        return [
            'enabled' => filter_var($cashInput['enabled'] ?? $defaultGroup['enabled'], FILTER_VALIDATE_BOOLEAN),
            'label' => $this->sanitizeNullableString($cashInput['label'] ?? null) ?? $defaultGroup['label'],
            'location' => $this->sanitizeNullableString($cashInput['location'] ?? $defaultGroup['location']),
            'hours' => $this->sanitizeNullableString($cashInput['hours'] ?? $defaultGroup['hours']),
            'schedule' => $cashSchedule,
            'instructions' => $this->sanitizeNullableString($cashInput['instructions'] ?? $defaultGroup['instructions']),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $rawAccounts
     * @return list<array{id: string, bank_name: ?string, branch: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>
     */
    private function normalizeBankAccounts(array $rawAccounts): array
    {
        $normalized = [];
        $usedIds = [];

        foreach (array_values($rawAccounts) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $this->uniqueAccountId($item['id'] ?? null, 'bank', $usedIds);
            $usedIds[] = $id;

            $normalized[] = [
                'id' => $id,
                'bank_name' => $this->sanitizeNullableString($item['bank_name'] ?? null),
                'branch' => $this->sanitizeNullableString($item['branch'] ?? null),
                'account_name' => $this->sanitizeNullableString($item['account_name'] ?? null),
                'account_number' => $this->sanitizeNullableString($item['account_number'] ?? null),
                'instructions' => $this->sanitizeNullableString($item['instructions'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, mixed>  $rawAccounts
     * @return list<array{id: string, provider: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>
     */
    private function normalizeEWalletAccounts(array $rawAccounts): array
    {
        $normalized = [];
        $usedIds = [];

        foreach (array_values($rawAccounts) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $this->uniqueAccountId($item['id'] ?? null, 'ewallet', $usedIds);
            $usedIds[] = $id;

            $normalized[] = [
                'id' => $id,
                'provider' => $this->sanitizeNullableString($item['provider'] ?? null),
                'account_name' => $this->sanitizeNullableString($item['account_name'] ?? null),
                'account_number' => $this->sanitizeNullableString($item['account_number'] ?? null),
                'instructions' => $this->sanitizeNullableString($item['instructions'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $usedIds
     */
    private function uniqueAccountId(mixed $rawId, string $prefix, array $usedIds): string
    {
        $candidate = $this->sanitizeNullableString($rawId);

        if ($candidate !== null && mb_strlen($candidate) <= 64 && ! in_array($candidate, $usedIds, true)) {
            return $candidate;
        }

        $counter = 1;

        while (in_array("{$prefix}-{$counter}", $usedIds, true)) {
            $counter++;
        }

        return "{$prefix}-{$counter}";
    }

    /**
     * @param  array<string, mixed>  $channels
     * @return list<array{
     *     group: string,
     *     key: string,
     *     evidence_channel: string,
     *     group_label: string,
     *     id: string,
     *     title: string,
     *     label: string,
     *     bank_name: ?string,
     *     branch: ?string,
     *     provider: ?string,
     *     provider_or_bank: string,
     *     account_name: ?string,
     *     account_number: string,
     *     instructions: ?string,
     *     option_label: string
     * }>
     */
    private function buildDigitalDisplayItems(array $channels, bool $includeIncomplete): array
    {
        $items = [];

        if ((bool) ($channels['bank_transfer']['enabled'] ?? false)) {
            $groupLabel = (string) ($channels['bank_transfer']['label'] ?? 'Bank Transfer');

            foreach ($channels['bank_transfer']['accounts'] ?? [] as $account) {
                $accountNumber = $this->sanitizeNullableString($account['account_number'] ?? null);

                if (! $includeIncomplete && $accountNumber === null) {
                    continue;
                }

                $bankName = $this->sanitizeNullableString($account['bank_name'] ?? null) ?? 'Bank Transfer';
                $branch = $this->sanitizeNullableString($account['branch'] ?? null);
                $numberText = $accountNumber ?? '';

                $items[] = [
                    'group' => 'bank_transfer',
                    'key' => 'bank_transfer',
                    'evidence_channel' => 'bank_transfer',
                    'group_label' => $groupLabel,
                    'id' => (string) $account['id'],
                    'title' => $bankName,
                    'label' => $bankName,
                    'bank_name' => $this->sanitizeNullableString($account['bank_name'] ?? null),
                    'branch' => $branch,
                    'provider' => null,
                    'provider_or_bank' => $bankName,
                    'account_name' => $this->sanitizeNullableString($account['account_name'] ?? null),
                    'account_number' => $numberText,
                    'instructions' => $this->sanitizeNullableString($account['instructions'] ?? null),
                    'option_label' => $numberText !== '' ? "{$bankName} — {$numberText}" : $bankName,
                ];
            }
        }

        if ((bool) ($channels['e_wallets']['enabled'] ?? false)) {
            $groupLabel = (string) ($channels['e_wallets']['label'] ?? 'E-Wallets');

            foreach ($channels['e_wallets']['accounts'] ?? [] as $account) {
                $accountNumber = $this->sanitizeNullableString($account['account_number'] ?? null);

                if (! $includeIncomplete && $accountNumber === null) {
                    continue;
                }

                $provider = $this->sanitizeNullableString($account['provider'] ?? null) ?? 'E-Wallet';
                $isGcash = strtolower($provider) === 'gcash';
                $numberText = $accountNumber ?? '';

                $items[] = [
                    'group' => 'e_wallets',
                    'key' => 'e_wallets',
                    'evidence_channel' => $isGcash ? 'gcash_manual' : 'e_wallet',
                    'group_label' => $groupLabel,
                    'id' => (string) $account['id'],
                    'title' => $provider,
                    'label' => $provider,
                    'bank_name' => null,
                    'branch' => null,
                    'provider' => $this->sanitizeNullableString($account['provider'] ?? null),
                    'provider_or_bank' => $provider,
                    'account_name' => $this->sanitizeNullableString($account['account_name'] ?? null),
                    'account_number' => $numberText,
                    'instructions' => $this->sanitizeNullableString($account['instructions'] ?? null),
                    'option_label' => $numberText !== '' ? "{$provider} — {$numberText}" : $provider,
                ];
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $electronicChannels
     * @param  ?array<string, mixed>  $cashChannel
     * @return array{
     *     title: string,
     *     electronicChannels: list<array<string, mixed>>,
     *     bankAccounts: list<array<string, mixed>>,
     *     eWalletAccounts: list<array<string, mixed>>,
     *     hasElectronicChannels: bool,
     *     hasEnabledDigitalChannels: bool,
     *     cashChannel: ?array<string, mixed>,
     *     cash: ?array<string, mixed>,
     *     hasCashChannel: bool,
     *     cashFallbackActive: bool,
     *     isOnsiteOnly: bool
     * }
     */
    private function assembleDisplayPayload(
        array $electronicChannels,
        bool $hasEnabledDigitalChannels,
        ?array $cashChannel,
        bool $cashFallbackActive,
    ): array {
        $bankAccounts = array_values(array_filter(
            $electronicChannels,
            static fn (array $item): bool => ($item['group'] ?? $item['key'] ?? null) === 'bank_transfer',
        ));
        $eWalletAccounts = array_values(array_filter(
            $electronicChannels,
            static fn (array $item): bool => ($item['group'] ?? $item['key'] ?? null) === 'e_wallets',
        ));

        return [
            'title' => 'How to pay',
            'electronicChannels' => $electronicChannels,
            'bankAccounts' => $bankAccounts,
            'eWalletAccounts' => $eWalletAccounts,
            'hasElectronicChannels' => $electronicChannels !== [],
            'hasEnabledDigitalChannels' => $hasEnabledDigitalChannels,
            'cashChannel' => $cashChannel,
            'cash' => $cashChannel,
            'hasCashChannel' => $cashChannel !== null,
            'cashFallbackActive' => $cashFallbackActive,
            'isOnsiteOnly' => $electronicChannels === [],
        ];
    }

    /**
     * @param  ?array<string, mixed>  $channels
     * @return array<string, mixed>|null
     */
    private function matchDigitalAccount(string $group, ?string $accountId = null, ?array $channels = null): ?array
    {
        $normalizedGroup = $this->normalizeGroupName($group);

        if ($normalizedGroup === null) {
            return null;
        }

        $groupAccounts = array_values(array_filter(
            $this->activeDigitalAccounts($channels),
            static fn (array $account): bool => $account['group'] === $normalizedGroup,
        ));

        if ($groupAccounts === []) {
            return null;
        }

        $trimmedId = $this->sanitizeNullableString($accountId);

        if ($trimmedId !== null) {
            foreach ($groupAccounts as $account) {
                if ($account['id'] === $trimmedId) {
                    return $account;
                }
            }

            return null;
        }

        if (in_array(strtolower(trim($group)), ['gcash', 'gcash_manual'], true)) {
            foreach ($groupAccounts as $account) {
                if (strtolower((string) ($account['provider'] ?? '')) === 'gcash') {
                    return $account;
                }
            }
        }

        return count($groupAccounts) === 1 ? $groupAccounts[0] : null;
    }

    private function normalizeGroupName(string $group): ?string
    {
        return match (strtolower(trim($group))) {
            'bank_transfer' => 'bank_transfer',
            'e_wallets', 'e_wallet', 'gcash', 'gcash_manual' => 'e_wallets',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $group
     */
    private function groupHasActiveAccounts(array $group): bool
    {
        foreach ($group['accounts'] ?? [] as $account) {
            if (is_array($account) && $this->sanitizeNullableString($account['account_number'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function isValidBankAccountNumber(string $accountNumber): bool
    {
        $compact = preg_replace('/[\s-]+/', '', $accountNumber) ?? '';

        return preg_match('/^\d{10,16}$/', $compact) === 1;
    }

    private function validateEWalletAccountNumber(?string $provider, string $accountNumber): ?string
    {
        $compact = preg_replace('/[\s-]+/', '', $accountNumber) ?? '';
        $providerKey = strtolower(trim((string) $provider));

        if (str_contains($providerKey, 'maya')) {
            if (preg_match('/^(?:0|\+?63)[89]\d{9}$/', $compact) === 1 || preg_match('/^\d{10,16}$/', $compact) === 1) {
                return null;
            }

            return 'Enter an 11-digit Philippine mobile number or a 10 to 16 digit Maya account number.';
        }

        if (str_contains($providerKey, 'gotyme')) {
            if (preg_match('/^\d{10,12}$/', $compact) === 1 || preg_match('/^(?:0|\+?63)[89]\d{9}$/', $compact) === 1) {
                return null;
            }

            return 'Enter a 10 to 12 digit GoTyme Bank account number or an 11-digit Philippine mobile number.';
        }

        if (in_array($providerKey, ['gcash', 'grabpay', 'shopeepay', 'palawanpay'], true)) {
            if (preg_match('/^(?:0|\+?63)[89]\d{9}$/', $compact) === 1) {
                return null;
            }

            return 'Enter an 11-digit Philippine mobile number (e.g. 0917 123 4567 or +63 917 123 4567).';
        }

        if (preg_match('/^(?:0|\+?63)[89]\d{9}$/', $compact) === 1 || preg_match('/^[A-Za-z0-9._-]{6,24}$/', $compact) === 1) {
            return null;
        }

        return 'Enter an 11-digit Philippine mobile number or a 6 to 24 character wallet/account ID.';
    }

    /**
     * @param  array<string, mixed>  $cashSource
     * @return array<string, mixed>
     */
    private function buildCashDisplayChannel(
        array $cashSource,
        bool $isFallback,
        bool $deriveHoursFromSchedule,
        ?CarbonImmutable $now,
    ): array {
        $schedule = CashierSchedule::normalize($cashSource['schedule'] ?? null);
        $hours = $deriveHoursFromSchedule
            ? CashierSchedule::formatHoursSummary($schedule)
            : ($this->sanitizeNullableString($cashSource['hours'] ?? null) ?? CashierSchedule::formatHoursSummary($schedule));

        return [
            'key' => 'cash',
            'evidence_channel' => 'cash',
            'enabled' => (bool) ($cashSource['enabled'] ?? false),
            'label' => $this->sanitizeNullableString($cashSource['label'] ?? null) ?? 'Cash (Campus Cashier)',
            'location' => $this->sanitizeNullableString($cashSource['location'] ?? null),
            'hours' => $hours,
            'instructions' => $this->sanitizeNullableString($cashSource['instructions'] ?? null),
            'schedule' => $schedule,
            'today_status' => CashierSchedule::todayStatus($schedule, $now),
            'weekly_summary_rows' => CashierSchedule::weeklySummaryRows($schedule),
            'cutoff_note' => $schedule['cutoff_note'],
            'upcoming_exceptions' => CashierSchedule::upcomingExceptions($schedule, $now),
            'calendar_months' => CashierSchedule::monthCalendars($schedule, $now, 3, $now),
            'is_fallback' => $isFallback,
        ];
    }

    /**
     * @return array{
     *     bank_transfer: array{enabled: bool, label: string, accounts: list<array{id: string, bank_name: ?string, branch: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>},
     *     e_wallets: array{enabled: bool, label: string, accounts: list<array{id: string, provider: ?string, account_name: ?string, account_number: ?string, instructions: ?string}>},
     *     cash: array{enabled: bool, label: string, location: ?string, hours: ?string, schedule: array<string, mixed>, instructions: ?string}
     * }
     */
    private function defaultChannels(): array
    {
        $configured = config('institution.payment_channels', []);
        $configured = is_array($configured) ? $configured : [];
        $bank = is_array($configured['bank_transfer'] ?? null) ? $configured['bank_transfer'] : [];
        $eWallets = is_array($configured['e_wallets'] ?? null) ? $configured['e_wallets'] : [];
        $cash = is_array($configured['cash'] ?? null) ? $configured['cash'] : [];
        $defaultSchedule = CashierSchedule::normalize($cash['schedule'] ?? null);

        $bankAccounts = is_array($bank['accounts'] ?? null)
            ? $this->normalizeBankAccounts($bank['accounts'])
            : [];

        $eWalletAccounts = is_array($eWallets['accounts'] ?? null)
            ? $this->normalizeEWalletAccounts($eWallets['accounts'])
            : [];

        return [
            'bank_transfer' => [
                'enabled' => filter_var($bank['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'label' => $this->sanitizeNullableString($bank['label'] ?? null) ?? 'Bank Transfer',
                'accounts' => $bankAccounts,
            ],
            'e_wallets' => [
                'enabled' => filter_var($eWallets['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'label' => $this->sanitizeNullableString($eWallets['label'] ?? null) ?? 'E-Wallets',
                'accounts' => $eWalletAccounts,
            ],
            'cash' => [
                'enabled' => filter_var($cash['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'label' => $this->sanitizeNullableString($cash['label'] ?? null) ?? 'Cash (Campus Cashier)',
                'location' => $this->sanitizeNullableString($cash['location'] ?? null) ?? 'Campus Cashier Window',
                'hours' => $this->sanitizeNullableString($cash['hours'] ?? null) ?? 'Monday to Friday, 8:00 AM - 5:00 PM',
                'schedule' => $defaultSchedule,
                'instructions' => $this->sanitizeNullableString($cash['instructions'] ?? null)
                    ?? 'Pay directly at the Campus Cashier and keep your Official Receipt.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     */
    private function hasAnyKey(array $source, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $stripped = strip_tags((string) $value);
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $stripped);
        $trimmed = trim((string) ($cleaned ?? $stripped));

        return $trimmed !== '' ? $trimmed : null;
    }
}
