<?php

namespace App\Filament\Pages;

use App\Actions\Finance\CashierSchedule;
use App\Actions\Finance\SchoolPaymentChannels;
use App\Models\SectionMeeting;
use App\Models\User;
use App\Support\DisplayDateTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentChannels extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Payment channels';

    protected static ?string $title = 'Payment channels';

    protected static ?string $slug = 'payment-channels';

    protected string $view = 'filament.pages.payment-channels';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $savedSnapshot = [];

    public ?int $sourceVersion = null;

    public ?string $updatedAt = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->canAuthenticate()
            && $user->hasRole(User::StaffRoleAccounting);
    }

    public function getSubheading(): ?string
    {
        return 'Configure verified bank transfer, e-wallet, and campus cashier details shown to students and applicants.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->loadChannels();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->channelSection(
                    key: 'bank_transfer',
                    title: 'Bank Transfer',
                    description: 'Bank deposit and online transfer accounts with student receipt upload.',
                    toggleLabel: 'Enable Bank Transfer',
                    icon: Heroicon::OutlinedBuildingLibrary,
                    fields: [
                        Repeater::make('bank_transfer.accounts')
                            ->hiddenLabel()
                            ->addActionLabel('Add bank account')
                            ->defaultItems(0)
                            ->collapsible(false)
                            ->collapseAllAction(fn (Action $action): Action => $action->hidden())
                            ->expandAllAction(fn (Action $action): Action => $action->hidden())
                            ->reorderableWithButtons(fn (?array $state): bool => count($state ?? []) > 1)
                            ->reorderableWithDragAndDrop(false)
                            ->truncateItemLabel()
                            ->itemLabel(fn (array $state): string => $this->formatDigitalAccountItemLabel($state, 'bank_name', 'Bank account'))
                            ->schema([
                                Hidden::make('id')
                                    ->default(fn (): string => (string) Str::uuid()),
                                Grid::make()
                                    ->gridContainer()
                                    ->columns(['default' => 1, '@md' => 2])
                                    ->schema([
                                        TextInput::make('bank_name')
                                            ->label('Bank name')
                                            ->placeholder('e.g. Land Bank of the Philippines')
                                            ->datalist(SchoolPaymentChannels::bankPresets())
                                            ->maxLength(120)
                                            ->live(onBlur: true)
                                            ->required(fn (Get $get): bool => (bool) $get('data.bank_transfer.enabled', isAbsolute: true)),
                                        TextInput::make('branch')
                                            ->label('Branch (optional)')
                                            ->placeholder('e.g. Main / Campus Branch')
                                            ->maxLength(120)
                                            ->live(onBlur: true),
                                        TextInput::make('account_name')
                                            ->label('Account name')
                                            ->placeholder('Servitech Institute Asia Inc.')
                                            ->maxLength(120)
                                            ->live(onBlur: true)
                                            ->required(fn (Get $get): bool => (bool) $get('data.bank_transfer.enabled', isAbsolute: true)),
                                        TextInput::make('account_number')
                                            ->label('Account number')
                                            ->placeholder('0012-3456-78')
                                            ->helperText(SchoolPaymentChannels::bankNumberHint(null))
                                            ->maxLength(100)
                                            ->extraInputAttributes(['class' => 'tala-tabular-input'])
                                            ->live(onBlur: true)
                                            ->required(fn (Get $get): bool => (bool) $get('data.bank_transfer.enabled', isAbsolute: true)),
                                    ]),
                                Textarea::make('instructions')
                                    ->label('Transfer instructions')
                                    ->placeholder('Include reference format, branch notes, or cutoff reminders.')
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->live(onBlur: true)
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ],
                    afterToggle: function (bool $state, Get $get, Set $set): void {
                        if ($state && $this->repeaterAccountCount($get('bank_transfer.accounts')) === 0) {
                            $set('bank_transfer.accounts', [
                                (string) Str::uuid() => [
                                    'id' => (string) Str::uuid(),
                                    'bank_name' => null,
                                    'branch' => null,
                                    'account_name' => null,
                                    'account_number' => null,
                                    'instructions' => null,
                                ],
                            ]);
                        }
                    },
                ),
                $this->channelSection(
                    key: 'e_wallets',
                    title: 'E-Wallets',
                    description: 'Official school e-wallet accounts with transfer receipt upload.',
                    toggleLabel: 'Enable E-Wallets',
                    icon: Heroicon::OutlinedDevicePhoneMobile,
                    fields: [
                        Repeater::make('e_wallets.accounts')
                            ->hiddenLabel()
                            ->addActionLabel('Add e-wallet account')
                            ->defaultItems(0)
                            ->collapsible(false)
                            ->collapseAllAction(fn (Action $action): Action => $action->hidden())
                            ->expandAllAction(fn (Action $action): Action => $action->hidden())
                            ->reorderableWithButtons(fn (?array $state): bool => count($state ?? []) > 1)
                            ->reorderableWithDragAndDrop(false)
                            ->truncateItemLabel()
                            ->itemLabel(fn (array $state): string => $this->formatDigitalAccountItemLabel($state, 'provider', 'E-wallet account'))
                            ->schema([
                                Hidden::make('id')
                                    ->default(fn (): string => (string) Str::uuid()),
                                Grid::make()
                                    ->gridContainer()
                                    ->columns(['default' => 1, '@md' => 2, '@2xl' => 3])
                                    ->schema([
                                        TextInput::make('provider')
                                            ->label('E-wallet provider')
                                            ->placeholder('e.g. GCash, Maya, GoTyme Bank')
                                            ->datalist(SchoolPaymentChannels::eWalletPresets())
                                            ->maxLength(120)
                                            ->live(onBlur: true)
                                            ->required(fn (Get $get): bool => (bool) $get('data.e_wallets.enabled', isAbsolute: true)),
                                        TextInput::make('account_name')
                                            ->label('Account name')
                                            ->placeholder('Servitech Institute Asia Inc.')
                                            ->maxLength(120)
                                            ->live(onBlur: true)
                                            ->required(fn (Get $get): bool => (bool) $get('data.e_wallets.enabled', isAbsolute: true)),
                                        TextInput::make('account_number')
                                            ->label('Mobile or account number')
                                            ->placeholder('0917 123 4567')
                                            ->helperText(fn (Get $get): string => SchoolPaymentChannels::eWalletNumberHint(is_string($get('provider')) ? $get('provider') : null))
                                            ->maxLength(100)
                                            ->extraInputAttributes(['class' => 'tala-tabular-input'])
                                            ->live(onBlur: true)
                                            ->required(fn (Get $get): bool => (bool) $get('data.e_wallets.enabled', isAbsolute: true)),
                                    ]),
                                Textarea::make('instructions')
                                    ->label('Wallet instructions')
                                    ->placeholder('Explain what reference number or screenshot details students and applicants should include.')
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->live(onBlur: true)
                                    ->columnSpanFull(),
                            ])
                            ->columnSpanFull(),
                    ],
                    afterToggle: function (bool $state, Get $get, Set $set): void {
                        if ($state && $this->repeaterAccountCount($get('e_wallets.accounts')) === 0) {
                            $set('e_wallets.accounts', [
                                (string) Str::uuid() => [
                                    'id' => (string) Str::uuid(),
                                    'provider' => null,
                                    'account_name' => null,
                                    'account_number' => null,
                                    'instructions' => null,
                                ],
                            ]);
                        }
                    },
                ),
                $this->channelSection(
                    key: 'cash',
                    title: 'Cash (Campus Cashier)',
                    description: 'Walk-in cashier location and window hours for onsite payments.',
                    toggleLabel: 'Enable Cash (Campus Cashier)',
                    icon: Heroicon::OutlinedBanknotes,
                    fields: [
                        TextInput::make('cash.location')
                            ->label('Cashier window or office')
                            ->placeholder('e.g. Campus Cashier Window, Ground Floor')
                            ->maxLength(160)
                            ->live(onBlur: true)
                            ->required(fn (Get $get): bool => (bool) $get('cash.enabled'))
                            ->columnSpanFull(),
                        $this->weeklyScheduleSection(),
                        $this->exceptionsScheduleSection(),
                        Textarea::make('cash.instructions')
                            ->label('Walk-in instructions')
                            ->placeholder('Remind students and applicants to bring their reference or keep their Official Receipt (OR).')
                            ->rows(2)
                            ->maxLength(500)
                            ->live(onBlur: true)
                            ->columnSpanFull(),
                    ],
                ),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $this->validate(collect(SchoolPaymentChannels::inputRules())
            ->mapWithKeys(fn (array $rules, string $field): array => ['data.'.$field => $rules])
            ->all());

        $state = $this->form->getState();
        $rawReason = $this->data['reason'] ?? ($state['reason'] ?? null);
        $reason = filled($rawReason) ? trim((string) $rawReason) : null;
        $current = app(SchoolPaymentChannels::class)->current();
        $rawBankAccounts = data_get($this->data, 'bank_transfer.accounts', []);
        $bankAccountKeys = is_array($rawBankAccounts) ? array_keys($rawBankAccounts) : [];
        $rawEWalletAccounts = data_get($this->data, 'e_wallets.accounts', []);
        $eWalletAccountKeys = is_array($rawEWalletAccounts) ? array_keys($rawEWalletAccounts) : [];
        $rawExceptions = data_get($this->data, 'cash.schedule.exceptions', []);
        $exceptionKeys = is_array($rawExceptions) ? array_keys($rawExceptions) : [];
        $schedule = $this->normalizeScheduleFromState(data_get($state, 'cash.schedule'));

        $payload = [
            'bank_transfer' => [
                'enabled' => (bool) data_get($state, 'bank_transfer.enabled', false),
                'label' => $current['bank_transfer']['label'],
                'accounts' => $this->normalizeRepeaterAccounts(
                    data_get($state, 'bank_transfer.accounts'),
                    ['bank_name', 'branch', 'account_name', 'account_number', 'instructions'],
                    fillBlankId: true,
                ),
            ],
            'e_wallets' => [
                'enabled' => (bool) data_get($state, 'e_wallets.enabled', false),
                'label' => $current['e_wallets']['label'],
                'accounts' => $this->normalizeRepeaterAccounts(
                    data_get($state, 'e_wallets.accounts'),
                    ['provider', 'account_name', 'account_number', 'instructions'],
                    fillBlankId: true,
                ),
            ],
            'cash' => [
                'enabled' => (bool) data_get($state, 'cash.enabled', false),
                'label' => $current['cash']['label'],
                'location' => data_get($state, 'cash.location'),
                'hours' => CashierSchedule::formatHoursSummary($schedule),
                'schedule' => $schedule,
                'instructions' => data_get($state, 'cash.instructions'),
            ],
        ];

        try {
            $updated = app(SchoolPaymentChannels::class)->update($payload, $actor, $reason);
        } catch (ValidationException $exception) {
            $mappedErrors = [];

            foreach ($exception->errors() as $key => $messages) {
                $mappedKey = $this->mapValidationErrorKey(
                    (string) $key,
                    $bankAccountKeys,
                    $eWalletAccountKeys,
                    $exceptionKeys,
                );
                $mappedErrors[$mappedKey] = $messages;
            }

            Notification::make()
                ->title('Payment channels could not be saved')
                ->body('Review the highlighted payment channel or cashier schedule fields.')
                ->danger()
                ->send();

            throw ValidationException::withMessages($mappedErrors);
        }

        $this->syncStateFromChannels($updated);

        Notification::make()
            ->title('Payment channels saved')
            ->body('Updated details are now active for student and applicant payment instructions.')
            ->success()
            ->send();
    }

    public function copyMondayHoursToWeekdays(): void
    {
        abort_unless(static::canAccess(), 403);

        $monday = data_get($this->data, 'cash.schedule.weekly.1', []);

        if (! is_array($monday)) {
            return;
        }

        $open = (bool) ($monday['open'] ?? false);
        $opensAt = $this->normalizeFormTime($monday['opens_at'] ?? null);
        $closesAt = $this->normalizeFormTime($monday['closes_at'] ?? null);

        for ($day = 2; $day <= 5; $day++) {
            data_set($this->data, "cash.schedule.weekly.{$day}.open", $open);
            data_set($this->data, "cash.schedule.weekly.{$day}.opens_at", $opensAt);
            data_set($this->data, "cash.schedule.weekly.{$day}.closes_at", $closesAt);
        }

        Notification::make()
            ->title('Monday hours copied to Tuesday through Friday.')
            ->success()
            ->send();
    }

    /**
     * @return list<array{
     *     id: int,
     *     starts_on: string,
     *     ends_on: string,
     *     date_label: string,
     *     label: string,
     *     exception_type: string,
     *     already_added: bool
     * }>
     */
    public function registrarHolidayHints(): array
    {
        $exceptions = data_get($this->data, 'cash.schedule.exceptions', []);

        return CashierSchedule::registrarHolidayHints(is_array($exceptions) ? $exceptions : []);
    }

    public function addRegistrarHolidayHint(string $startsOn, string $endsOn, string $label): void
    {
        abort_unless(static::canAccess(), 403);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn) !== 1) {
            return;
        }

        $exceptions = data_get($this->data, 'cash.schedule.exceptions', []);
        $exceptions = is_array($exceptions) ? $exceptions : [];

        foreach ($exceptions as $existing) {
            if (! is_array($existing)) {
                continue;
            }

            $normalized = CashierSchedule::normalize(['exceptions' => [$existing]])['exceptions'][0] ?? null;

            if (is_array($normalized) && $normalized['starts_on'] === $startsOn && $normalized['ends_on'] === $endsOn) {
                return;
            }
        }

        $cleanLabel = trim($label);

        $exceptions[(string) Str::uuid()] = [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => CashierSchedule::StatusClosed,
            'opens_at' => null,
            'closes_at' => null,
            'label' => $cleanLabel !== '' ? $cleanLabel : 'Term exception',
        ];

        data_set($this->data, 'cash.schedule.exceptions', $exceptions);
    }

    /**
     * @return array{enabledCount: int, headline: string, caption: string, isWarning: bool, isDirty: bool, savedAt: ?string}
     */
    public function summaryData(): array
    {
        $enabledNames = collect([
            'bank_transfer' => 'bank transfer',
            'e_wallets' => 'e-wallets',
            'cash' => 'cash at the campus cashier',
        ])->filter(fn (string $label, string $key): bool => (bool) data_get($this->data, "{$key}.enabled", false));

        $enabledCount = $enabledNames->count();
        $live = $this->comparableSnapshot($this->data ?? []);
        $isDirty = $this->savedSnapshot !== [] && $live !== $this->savedSnapshot;

        if ($isDirty) {
            $headline = 'Changes not saved';
            $caption = $enabledCount === 0
                ? 'All methods are off in this preview. Saving will show standard campus cashier instructions. Students still see the saved instructions until then.'
                : 'The preview shows your changes. Students and applicants see the saved instructions until you save.';
        } elseif ($enabledCount === 0) {
            $headline = 'Cashier instructions shown';
            $caption = 'All methods are off. Students and applicants see the standard campus cashier instructions.';
        } else {
            $headline = filled($this->updatedAt) ? 'Payment instructions saved' : 'Payment methods shown';
            $caption = 'Students and applicants can pay by '.$enabledNames->values()->join(', ', ' or ').'.';
        }

        return [
            'enabledCount' => $enabledCount,
            'headline' => $headline,
            'caption' => $caption,
            'isWarning' => $enabledCount === 0,
            'isDirty' => $isDirty,
            'savedAt' => filled($this->updatedAt)
                ? DisplayDateTime::format(CarbonImmutable::parse($this->updatedAt))
                : null,
        ];
    }

    /**
     * @return array{
     *     display: array{
     *         electronicChannels: list<array<string, mixed>>,
     *         cashChannel: ?array<string, mixed>,
     *         cashFallbackActive: bool,
     *         isOnsiteOnly: bool
     *     },
     *     cashFallbackActive: bool,
     *     isDirty: bool
     * }
     */
    public function previewData(): array
    {
        $live = $this->comparableSnapshot($this->data ?? []);
        $display = app(SchoolPaymentChannels::class)->forDisplay($live);

        return [
            'display' => $display,
            'cashFallbackActive' => $display['cashFallbackActive'],
            'isDirty' => $this->savedSnapshot !== [] && ($live !== $this->savedSnapshot || filled($this->data['reason'] ?? null)),
        ];
    }

    private function weeklyScheduleSection(): Section
    {
        $dayRows = [];

        foreach (SectionMeeting::dayOptions() as $day => $dayName) {
            $dayRows[] = Flex::make([
                Toggle::make("cash.schedule.weekly.{$day}.open")
                    ->label($dayName)
                    ->onColor('success')
                    ->live(),
                Grid::make(2)
                    ->extraAttributes(['class' => 'payment-channels-weekly-row__times'])
                    ->schema([
                        TimePicker::make("cash.schedule.weekly.{$day}.opens_at")
                            ->label('Opens')
                            ->seconds(false)
                            ->format('H:i')
                            ->timezone((string) config('app.timezone'))
                            ->extraInputAttributes([
                                'aria-label' => "{$dayName} opens at",
                                'class' => 'tala-tabular-input',
                            ])
                            ->required(fn (Get $get): bool => (bool) $get('cash.enabled') && (bool) $get("cash.schedule.weekly.{$day}.open"))
                            ->live(onBlur: true),
                        TimePicker::make("cash.schedule.weekly.{$day}.closes_at")
                            ->label('Closes')
                            ->seconds(false)
                            ->format('H:i')
                            ->timezone((string) config('app.timezone'))
                            ->extraInputAttributes([
                                'aria-label' => "{$dayName} closes at",
                                'class' => 'tala-tabular-input',
                            ])
                            ->required(fn (Get $get): bool => (bool) $get('cash.enabled') && (bool) $get("cash.schedule.weekly.{$day}.open"))
                            ->live(onBlur: true),
                    ])
                    ->hidden(fn (Get $get): bool => ! (bool) $get("cash.schedule.weekly.{$day}.open"))
                    ->grow(),
                Text::make('Closed')
                    ->badge()
                    ->color('gray')
                    ->visible(fn (Get $get): bool => ! (bool) $get("cash.schedule.weekly.{$day}.open"))
                    ->extraAttributes(['class' => 'payment-channels-weekly-row__closed-badge']),
            ])
                ->extraAttributes(fn (Get $get): array => [
                    'class' => 'payment-channels-weekly-row '.((bool) $get("cash.schedule.weekly.{$day}.open")
                        ? 'payment-channels-weekly-row--open'
                        : 'payment-channels-weekly-row--closed'),
                ]);
        }

        return Section::make('Weekly cashier operating hours')
            ->description('Set regular Asia/Manila campus cashier hours for each day of the week.')
            ->compact()
            ->extraAttributes(['class' => 'payment-channels-subpanel payment-channels-weekly-section'])
            ->afterHeader([
                Action::make('copyMondayToWeekdays')
                    ->label('Copy Mon hours to Tue–Fri')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->size('xs')
                    ->action(fn () => $this->copyMondayHoursToWeekdays()),
            ])
            ->schema([
                Hidden::make('cash.schedule.timezone')
                    ->default(CashierSchedule::defaultTimezone()),
                Group::make([
                    Html::make(new HtmlString('<div class="payment-channels-weekly-head" aria-hidden="true"><span>Day</span> <span>Opens</span> <span>Closes</span></div>')),
                    ...$dayRows,
                ])
                    ->extraAttributes(['class' => 'payment-channels-weekly-list']),
                TextInput::make('cash.schedule.cutoff_note')
                    ->label('Break or cutoff note (optional)')
                    ->placeholder('e.g. 12:00 PM – 1:00 PM lunch break · Payments after 4:30 PM post next business day')
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();
    }

    private function exceptionsScheduleSection(): Section
    {
        return Section::make('Holiday closures & special hours')
            ->description('Add date-specific cashier closures or modified hours independently of academic term calendars.')
            ->compact()
            ->extraAttributes(['class' => 'payment-channels-subpanel payment-channels-exceptions-section'])
            ->schema([
                Repeater::make('cash.schedule.exceptions')
                    ->hiddenLabel()
                    ->addActionLabel('Add closure or special-hours date')
                    ->defaultItems(0)
                    ->collapsible(false)
                    ->collapseAllAction(fn (Action $action): Action => $action->hidden())
                    ->expandAllAction(fn (Action $action): Action => $action->hidden())
                    ->itemLabel(fn (array $state): string => CashierSchedule::formatExceptionItemLabel($state))
                    ->truncateItemLabel()
                    ->reorderable(false)
                    ->gridContainer()
                    ->columns(['default' => 1, '@sm' => 2])
                    ->schema([
                        DatePicker::make('starts_on')
                            ->label('Start date')
                            ->required()
                            ->live(),
                        DatePicker::make('ends_on')
                            ->label('End date (optional)')
                            ->live(),
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                CashierSchedule::StatusClosed => 'Closed (Holiday / No Cashier)',
                                CashierSchedule::StatusCustomHours => 'Special / Modified Hours',
                            ])
                            ->default(CashierSchedule::StatusClosed)
                            ->required()
                            ->live()
                            ->columnSpanFull(),
                        TimePicker::make('opens_at')
                            ->label('Opens')
                            ->seconds(false)
                            ->format('H:i')
                            ->timezone((string) config('app.timezone'))
                            ->visible(fn (Get $get): bool => $get('status') === CashierSchedule::StatusCustomHours)
                            ->required(fn (Get $get): bool => $get('status') === CashierSchedule::StatusCustomHours)
                            ->live(onBlur: true),
                        TimePicker::make('closes_at')
                            ->label('Closes')
                            ->seconds(false)
                            ->format('H:i')
                            ->timezone((string) config('app.timezone'))
                            ->visible(fn (Get $get): bool => $get('status') === CashierSchedule::StatusCustomHours)
                            ->required(fn (Get $get): bool => $get('status') === CashierSchedule::StatusCustomHours)
                            ->live(onBlur: true),
                        TextInput::make('label')
                            ->label('Reason / Holiday name')
                            ->placeholder("e.g. All Saints' Day or Enrollment Saturday")
                            ->maxLength(160)
                            ->required()
                            ->live(onBlur: true)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Html::make(fn (): HtmlString => $this->renderRegistrarHolidayHintsHtml())
                    ->visible(fn (): bool => $this->registrarHolidayHints() !== [])
                    ->columnSpanFull(),
            ])
            ->columnSpanFull();
    }

    private function renderRegistrarHolidayHintsHtml(): HtmlString
    {
        $hints = $this->registrarHolidayHints();

        if ($hints === []) {
            return new HtmlString('');
        }

        $html = Blade::render(<<<'BLADE'
            <div class="payment-channels-hints" aria-label="Registrar academic calendar reference">
                <div class="payment-channels-hints__head">
                    <p class="payment-channels-hints__title">Registrar academic calendar reference</p>
                    <p class="payment-channels-hints__sub">Informational only: campus cashier closures stay independent unless you add them.</p>
                </div>
                <ul class="payment-channels-hints__list">
                    @foreach ($hints as $hint)
                        <li class="payment-channels-hints__item" wire:key="registrar-hint-{{ $hint['id'] }}">
                            <div class="payment-channels-hints__meta">
                                <strong>{{ $hint['label'] }}</strong>
                                <span>{{ $hint['date_label'] }} · {{ \Illuminate\Support\Str::headline($hint['exception_type']) }}</span>
                            </div>
                            <div class="payment-channels-hints__action">
                                @if ($hint['already_added'])
                                    <x-filament::badge color="success" size="sm">Added</x-filament::badge>
                                @else
                                    <x-filament::button
                                        type="button"
                                        size="sm"
                                        color="gray"
                                        wire:click="addRegistrarHolidayHint({{ \Illuminate\Support\Js::from($hint['starts_on']) }}, {{ \Illuminate\Support\Js::from($hint['ends_on']) }}, {{ \Illuminate\Support\Js::from($hint['label']) }})"
                                    >
                                        + Add to cashier closures
                                    </x-filament::button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        BLADE, ['hints' => $hints]);

        return new HtmlString($html);
    }

    /**
     * @param  list<int|string>  $bankAccountKeys
     * @param  list<int|string>  $eWalletAccountKeys
     * @param  list<int|string>  $exceptionKeys
     */
    private function mapValidationErrorKey(
        string $key,
        array $bankAccountKeys,
        array $eWalletAccountKeys,
        array $exceptionKeys,
    ): string {
        if (preg_match('/^bank_transfer\.accounts\.(\d+)\.(.+)$/', $key, $matches) === 1) {
            $numericIndex = (int) $matches[1];
            $actualKey = $bankAccountKeys[$numericIndex] ?? $numericIndex;

            return "data.bank_transfer.accounts.{$actualKey}.{$matches[2]}";
        }

        if (preg_match('/^e_wallets\.accounts\.(\d+)\.(.+)$/', $key, $matches) === 1) {
            $numericIndex = (int) $matches[1];
            $actualKey = $eWalletAccountKeys[$numericIndex] ?? $numericIndex;

            return "data.e_wallets.accounts.{$actualKey}.{$matches[2]}";
        }

        if (preg_match('/^cash\.schedule\.exceptions\.(\d+)\.(.+)$/', $key, $matches) === 1) {
            $numericIndex = (int) $matches[1];
            $actualKey = $exceptionKeys[$numericIndex] ?? $numericIndex;

            return "data.cash.schedule.exceptions.{$actualKey}.{$matches[2]}";
        }

        return str_starts_with($key, 'data.') ? $key : 'data.'.$key;
    }

    /**
     * @return array{
     *     timezone: string,
     *     cutoff_note: ?string,
     *     weekly: array<int, array{open: bool, opens_at: ?string, closes_at: ?string}>,
     *     exceptions: list<array{starts_on: ?string, ends_on: ?string, status: string, opens_at: ?string, closes_at: ?string, label: ?string}>
     * }
     */
    private function normalizeScheduleFromState(mixed $rawSchedule): array
    {
        if (! is_array($rawSchedule)) {
            return CashierSchedule::defaultSchedule();
        }

        $cleaned = $rawSchedule;

        if (is_array($cleaned['weekly'] ?? null)) {
            foreach ($cleaned['weekly'] as $day => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                if (array_key_exists('opens_at', $entry)) {
                    $cleaned['weekly'][$day]['opens_at'] = $this->normalizeFormTime($entry['opens_at']);
                }

                if (array_key_exists('closes_at', $entry)) {
                    $cleaned['weekly'][$day]['closes_at'] = $this->normalizeFormTime($entry['closes_at']);
                }
            }
        }

        if (is_array($cleaned['exceptions'] ?? null)) {
            foreach ($cleaned['exceptions'] as $index => $exception) {
                if (! is_array($exception)) {
                    continue;
                }

                if (array_key_exists('starts_on', $exception)) {
                    $cleaned['exceptions'][$index]['starts_on'] = $this->normalizeFormDate($exception['starts_on']);
                }

                if (array_key_exists('ends_on', $exception)) {
                    $cleaned['exceptions'][$index]['ends_on'] = $this->normalizeFormDate($exception['ends_on']);
                }

                if (array_key_exists('opens_at', $exception)) {
                    $cleaned['exceptions'][$index]['opens_at'] = $this->normalizeFormTime($exception['opens_at']);
                }

                if (array_key_exists('closes_at', $exception)) {
                    $cleaned['exceptions'][$index]['closes_at'] = $this->normalizeFormTime($exception['closes_at']);
                }
            }
        }

        return CashierSchedule::normalize($cleaned);
    }

    private function normalizeFormTime(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T\s](\d{2}:\d{2})(?::\d{2})?$/', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }

    private function normalizeFormDate(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T\s].+$/', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }

    /**
     * @param  list<Component>  $fields
     */
    private function channelSection(
        string $key,
        string $title,
        string $description,
        string $toggleLabel,
        BackedEnum $icon,
        array $fields,
        ?\Closure $afterToggle = null,
    ): Section {
        $toggle = Toggle::make("{$key}.enabled")
            ->label($toggleLabel)
            ->hiddenLabel()
            ->live();

        if ($afterToggle !== null) {
            $toggle->afterStateUpdated($afterToggle);
        }

        return Section::make($title)
            ->description($description)
            ->icon($icon)
            ->extraAttributes(fn (Get $get): array => [
                'class' => 'payment-channels-section '.((bool) $get("{$key}.enabled")
                    ? 'payment-channel-card--active'
                    : 'payment-channel-card--collapsed'),
            ])
            ->afterHeader([
                $toggle,
            ])
            ->schema($fields);
    }

    private function formatDigitalAccountItemLabel(array $state, string $primaryField, string $fallback): string
    {
        $primary = $state[$primaryField] ?? null;

        return is_string($primary) && filled(trim($primary)) ? trim($primary) : $fallback;
    }

    private function repeaterAccountCount(mixed $accounts): int
    {
        return is_array($accounts) ? count($accounts) : 0;
    }

    /**
     * @param  list<string>  $fields
     * @return list<array<string, string>>
     */
    private function normalizeRepeaterAccounts(mixed $accounts, array $fields, bool $fillBlankId): array
    {
        if (! is_array($accounts)) {
            return [];
        }

        $normalized = [];

        foreach ($accounts as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = is_string($item['id'] ?? null) ? trim($item['id']) : '';
            if ($id === '' && $fillBlankId) {
                $id = (string) Str::uuid();
            }

            $row = ['id' => $id];

            foreach ($fields as $field) {
                $row[$field] = is_string($item[$field] ?? null) ? trim($item[$field]) : '';
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    private function loadChannels(): void
    {
        $channels = app(SchoolPaymentChannels::class)->current();
        $this->syncStateFromChannels($channels);
    }

    /** @param array<string, mixed> $channels */
    private function syncStateFromChannels(array $channels): void
    {
        $this->sourceVersion = isset($channels['source_version']) ? (int) $channels['source_version'] : null;
        $this->updatedAt = isset($channels['updated_at']) && is_string($channels['updated_at']) ? $channels['updated_at'] : null;

        $cash = is_array($channels['cash'] ?? null) ? $channels['cash'] : [];
        $cash['schedule'] = CashierSchedule::normalize($cash['schedule'] ?? null);

        $filled = [
            'bank_transfer' => $channels['bank_transfer'],
            'e_wallets' => $channels['e_wallets'],
            'cash' => $cash,
            'reason' => null,
        ];

        $this->form->fill($filled);
        $this->data['reason'] = null;
        $this->savedSnapshot = $this->comparableSnapshot($filled);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{
     *     bank_transfer: array{enabled: bool, accounts: list<array<string, string>>},
     *     e_wallets: array{enabled: bool, accounts: list<array<string, string>>},
     *     cash: array{enabled: bool, location: string, hours: string, schedule: array<string, mixed>, instructions: string}
     * }
     */
    private function comparableSnapshot(array $state): array
    {
        $schedule = $this->normalizeScheduleFromState(data_get($state, 'cash.schedule'));

        return [
            'bank_transfer' => [
                'enabled' => (bool) data_get($state, 'bank_transfer.enabled', false),
                'accounts' => $this->normalizeRepeaterAccounts(
                    data_get($state, 'bank_transfer.accounts'),
                    ['bank_name', 'branch', 'account_name', 'account_number', 'instructions'],
                    fillBlankId: false,
                ),
            ],
            'e_wallets' => [
                'enabled' => (bool) data_get($state, 'e_wallets.enabled', false),
                'accounts' => $this->normalizeRepeaterAccounts(
                    data_get($state, 'e_wallets.accounts'),
                    ['provider', 'account_name', 'account_number', 'instructions'],
                    fillBlankId: false,
                ),
            ],
            'cash' => [
                'enabled' => (bool) data_get($state, 'cash.enabled', false),
                'location' => is_string(data_get($state, 'cash.location')) ? trim(data_get($state, 'cash.location')) : '',
                'hours' => CashierSchedule::formatHoursSummary($schedule),
                'schedule' => $schedule,
                'instructions' => is_string(data_get($state, 'cash.instructions')) ? trim(data_get($state, 'cash.instructions')) : '',
            ],
        ];
    }
}
