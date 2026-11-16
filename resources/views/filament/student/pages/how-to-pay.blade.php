@php
    $resolvedVariant = isset($variant) && in_array($variant, ['page', 'compact', 'preview'], true)
        ? $variant
        : ((bool) ($compact ?? false) ? 'compact' : 'page');
    $surface = $surface ?? 'finance';

    $resolvedDisplay = is_array($display ?? null)
        ? $display
        : app(\App\Actions\Finance\SchoolPaymentChannels::class)->forDisplayFromEnabledList(
            is_array($channels ?? null) ? $channels : (method_exists($channels ?? null, 'all') ? $channels->all() : []),
        );

    $electronicChannels = array_values(array_filter(
        is_array($resolvedDisplay['electronicChannels'] ?? null) ? $resolvedDisplay['electronicChannels'] : [],
        static fn (mixed $channel): bool => is_array($channel),
    ));

    $bankAccounts = array_values(array_filter(
        is_array($resolvedDisplay['bankAccounts'] ?? null) ? $resolvedDisplay['bankAccounts'] : $electronicChannels,
        static fn (mixed $channel): bool => is_array($channel) && (($channel['group'] ?? $channel['key'] ?? null) === 'bank_transfer'),
    ));
    $eWalletAccounts = array_values(array_filter(
        is_array($resolvedDisplay['eWalletAccounts'] ?? null) ? $resolvedDisplay['eWalletAccounts'] : $electronicChannels,
        static fn (mixed $channel): bool => is_array($channel) && in_array($channel['group'] ?? $channel['key'] ?? null, ['e_wallets', 'gcash'], true),
    ));

    $digitalGroups = [];
    if ($bankAccounts !== []) {
        $digitalGroups[] = [
            'key' => 'bank_transfer',
            'label' => trim((string) ($bankAccounts[0]['group_label'] ?? 'Bank Transfer')) ?: 'Bank Transfer',
            'icon' => 'heroicon-o-building-library',
            'badge' => 'Bank Transfer',
            'accounts' => $bankAccounts,
        ];
    }
    if ($eWalletAccounts !== []) {
        $digitalGroups[] = [
            'key' => 'e_wallets',
            'label' => trim((string) ($eWalletAccounts[0]['group_label'] ?? 'E-Wallets')) ?: 'E-Wallets',
            'icon' => 'heroicon-o-device-phone-mobile',
            'badge' => 'E-Wallet',
            'accounts' => $eWalletAccounts,
        ];
    }

    $cashChannel = is_array($resolvedDisplay['cashChannel'] ?? null) ? $resolvedDisplay['cashChannel'] : null;
    $cashFallbackActive = (bool) ($resolvedDisplay['cashFallbackActive'] ?? false);

    if ($cashChannel === null && $cashFallbackActive) {
        $cashChannel = app(\App\Actions\Finance\SchoolPaymentChannels::class)->forDisplayFromEnabledList([])['cashChannel'] ?? null;
    }

    $hasElectronic = $digitalGroups !== [];
    $hasCash = is_array($cashChannel);
    $cardHeadingTag = $resolvedVariant === 'page' ? 'h3' : 'h4';
    $accountHeadingTag = $resolvedVariant === 'page' ? 'h4' : 'h5';
    $rootTag = $resolvedVariant === 'preview' ? 'div' : 'section';
    $headingId = $resolvedVariant === 'compact' ? 'how-to-pay-modal-heading' : 'how-to-pay-heading';
    $groupHeadingPrefix = $resolvedVariant.'-'.$surface.'-'.substr(md5(json_encode($electronicChannels) ?: '[]'), 0, 8);

    $rootClass = match ($resolvedVariant) {
        'compact' => 'tala-context-inset tala-how-to-pay tala-where-to-pay tala-how-to-pay--compact tala-where-to-pay--compact',
        'preview' => 'tala-how-to-pay tala-where-to-pay tala-how-to-pay--preview tala-where-to-pay--preview',
        default => 'tala-task-surface tala-how-to-pay tala-where-to-pay tala-how-to-pay--page tala-where-to-pay--page',
    };

    $todayStatus = is_array($cashChannel['today_status'] ?? null) ? $cashChannel['today_status'] : null;
    $todayTone = in_array($todayStatus['tone'] ?? '', ['success', 'warning', 'gray'], true)
        ? $todayStatus['tone']
        : 'gray';
    $weeklyRows = is_array($cashChannel['weekly_summary_rows'] ?? null) ? $cashChannel['weekly_summary_rows'] : [];
    $upcomingExceptions = is_array($cashChannel['upcoming_exceptions'] ?? null) ? $cashChannel['upcoming_exceptions'] : [];
    $calendarMonths = is_array($cashChannel['calendar_months'] ?? null) ? $cashChannel['calendar_months'] : [];
    $calendarKey = md5(json_encode($calendarMonths) ?: '[]');
@endphp

<{{ $rootTag }}
    class="{{ $rootClass }}"
    @if ($resolvedVariant !== 'preview')
        aria-labelledby="{{ $headingId }}"
    @endif
>
    @if ($resolvedVariant === 'page')
        <header class="tala-how-to-pay__header tala-where-to-pay__header">
            <h2 id="{{ $headingId }}" class="student-record-heading">
                <x-filament::icon icon="heroicon-o-credit-card" aria-hidden="true" />
                <span>How to pay</span>
            </h2>
            <p class="tala-how-to-pay__lead tala-where-to-pay__lead">
                @if ($hasElectronic)
                    Pay through an enabled bank transfer or e-wallet account below, then submit your receipt for Accounting review.
                @else
                    Electronic transfer channels are currently unavailable. Pay onsite at the Campus Cashier below.
                @endif
            </p>
        </header>
    @elseif ($resolvedVariant === 'compact')
        <header class="tala-how-to-pay__header tala-where-to-pay__header">
            <h3 id="{{ $headingId }}" class="tala-how-to-pay__compact-heading tala-where-to-pay__compact-heading">
                <x-filament::icon icon="heroicon-o-credit-card" aria-hidden="true" />
                <span>How to pay</span>
            </h3>
            <p class="tala-how-to-pay__lead tala-where-to-pay__lead">
                @if ($hasElectronic)
                    Send your payment to an enabled bank or e-wallet account below, then attach your receipt in the form.
                @else
                    Electronic transfer channels are currently turned off. Pay onsite at the Campus Cashier below.
                @endif
            </p>
        </header>
    @endif

    @if ($hasElectronic)
        <div class="tala-how-to-pay__groups">
            @foreach ($digitalGroups as $group)
                @php
                    $isBankGroup = $group['key'] === 'bank_transfer';
                    $accounts = $group['accounts'];
                    $configuredAccountCount = count(array_filter($accounts, fn (array $account): bool => filled($account['account_name'] ?? null) && filled($account['account_number'] ?? null)));
                    $isMultiAccounts = count($accounts) > 1;
                    $groupHeadingId = "how-to-pay-group-{$groupHeadingPrefix}-{$group['key']}";
                    $signatureCounts = [];
                    foreach ($accounts as $acct) {
                        $acctTitle = trim((string) ($acct['title'] ?? $acct['provider_or_bank'] ?? ($isBankGroup ? ($acct['bank_name'] ?? '') : ($acct['provider'] ?? '')) ?: ($acct['label'] ?? $group['badge'])));
                        $acctBranch = $isBankGroup ? trim((string) ($acct['branch'] ?? '')) : '';
                        $sig = strtolower($acctTitle.'|'.$acctBranch);
                        $signatureCounts[$sig] = ($signatureCounts[$sig] ?? 0) + 1;
                    }
                @endphp
                <section class="tala-how-to-pay__group" aria-labelledby="{{ $groupHeadingId }}">
                    <header class="tala-how-to-pay__group-head">
                        <{{ $cardHeadingTag }} id="{{ $groupHeadingId }}" class="tala-how-to-pay__group-title">
                            <x-filament::icon :icon="$group['icon']" aria-hidden="true" />
                            <span>{{ $group['label'] }}</span>
                        </{{ $cardHeadingTag }}>
                        <span class="tala-how-to-pay__group-count">{{ $resolvedVariant === 'preview' && $configuredAccountCount === 0 ? 'Details incomplete' : count($accounts).' '.\Illuminate\Support\Str::plural('account', count($accounts)) }}</span>
                    </header>

                    <div @class([
                        'tala-how-to-pay__accounts-grid',
                        'tala-where-to-pay__electronic-grid',
                        'tala-how-to-pay__accounts-grid--multi' => $isMultiAccounts,
                        'tala-where-to-pay__electronic-grid--two' => $isMultiAccounts,
                    ])>
                        @foreach ($accounts as $channel)
                            @php
                                $cardTitle = trim((string) ($channel['title'] ?? $channel['provider_or_bank'] ?? ($isBankGroup ? ($channel['bank_name'] ?? '') : ($channel['provider'] ?? '')) ?: ($channel['label'] ?? $group['badge'])));
                                $branch = $isBankGroup ? trim((string) ($channel['branch'] ?? '')) : '';
                                $accountName = trim((string) ($channel['account_name'] ?? ''));
                                $accountNumber = trim((string) ($channel['account_number'] ?? ''));
                                $instructions = trim((string) ($channel['instructions'] ?? ''));
                                $isBlankPreview = $resolvedVariant === 'preview' && $accountName === '' && $accountNumber === '' && $branch === '' && $instructions === '';
                                $accountNumberLabel = $isBankGroup ? 'Account number' : 'Mobile / account number';
                                $showAccountNameRow = $accountName !== '' || $resolvedVariant === 'preview';
                                $showBranchRow = $isBankGroup && $branch !== '';
                                $sig = strtolower($cardTitle.'|'.$branch);
                                $needsDisambiguation = ($signatureCounts[$sig] ?? 1) > 1;
                                $copyLabelSubject = $cardTitle
                                    .($branch !== '' ? ' ('.$branch.')' : '')
                                    .($needsDisambiguation && $accountNumber !== '' ? ' ending '.substr($accountNumber, -4) : '');
                                $copyScreenReaderSuffix = $copyLabelSubject.' '.strtolower($accountNumberLabel);
                            @endphp
                            <article class="tala-channel-card tala-channel-card--electronic">
                                @if ($isBlankPreview)
                                    <p class="tala-channel-preview-empty">{{ $isBankGroup ? 'Add bank account details above to preview payment instructions.' : 'Add e-wallet account details above to preview payment instructions.' }}</p>
                                @else
                                    <header class="tala-channel-card__head">
                                        <span class="tala-workspace-card__icon tala-channel-card__icon" aria-hidden="true">
                                            <x-filament::icon :icon="$group['icon']" />
                                        </span>
                                        <div class="tala-channel-card__heading-wrap">
                                            <{{ $accountHeadingTag }} class="tala-channel-card__title">{{ $cardTitle }}</{{ $accountHeadingTag }}>
                                        </div>
                                    </header>

                                    @if ($accountNumber !== '' || $resolvedVariant === 'preview')
                                        <div class="tala-channel-copy-tile" @if ($accountNumber !== '') data-tala-reference @endif>
                                            <div class="tala-channel-copy-tile__meta">
                                                <span class="tala-channel-copy-tile__label">{{ $accountNumberLabel }}</span>
                                                @if ($accountNumber !== '')
                                                    <span class="tala-reference-value">{{ $accountNumber }}</span>
                                                @else
                                                    <span class="tala-reference-value tala-reference-value--empty">Not set yet</span>
                                                @endif
                                            </div>
                                            @if ($accountNumber !== '')
                                                <button
                                                    type="button"
                                                    class="tala-copy-reference"
                                                    data-copy-reference="{{ $accountNumber }}"
                                                    data-copy-feedback="{{ $copyScreenReaderSuffix }} copied."
                                                >
                                                    <x-filament::icon icon="heroicon-o-clipboard-document" aria-hidden="true" />
                                                    <span>Copy<span class="sr-only"> {{ $copyScreenReaderSuffix }}</span></span>
                                                </button>
                                                <span class="tala-reference-feedback" role="status" aria-live="polite"></span>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($showAccountNameRow || $showBranchRow)
                                        <dl @class(['tala-channel-meta-pair', 'tala-channel-meta-pair--two' => $showAccountNameRow && $showBranchRow])>
                                            @if ($showAccountNameRow)
                                                <div>
                                                    <dt>Account name</dt>
                                                    <dd @class(['tala-channel-facts__empty' => $accountName === ''])>
                                                        {{ $accountName !== '' ? $accountName : 'Not set yet' }}
                                                    </dd>
                                                </div>
                                            @endif

                                            @if ($showBranchRow)
                                                <div>
                                                    <dt>Branch</dt>
                                                    <dd>{{ $branch }}</dd>
                                                </div>
                                            @endif
                                        </dl>
                                    @endif

                                    @if ($instructions !== '')
                                        <p class="tala-channel-card__instructions"><span class="sr-only">Instructions: </span>{{ $instructions }}</p>
                                    @endif
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    @if ($hasCash)
        @php
            $cashLocation = trim((string) ($cashChannel['location'] ?? ''));
            $cashHours = trim((string) ($cashChannel['hours'] ?? ''));
            $cutoffNote = trim((string) ($cashChannel['cutoff_note'] ?? ''));
            $cashInstructions = trim((string) ($cashChannel['instructions'] ?? ''));
            $cashLabel = filled($cashChannel['label'] ?? null)
                ? (string) $cashChannel['label']
                : 'Cash (Campus Cashier)';
        @endphp

        @if ($resolvedVariant === 'compact' && $hasElectronic)
            <div class="tala-how-to-pay__cash-footer tala-where-to-pay__cash-footer">
                <div class="tala-how-to-pay__cash-footer-meta tala-where-to-pay__cash-footer-meta">
                    @if ($todayStatus !== null)
                        <x-filament::badge :color="$todayTone" size="sm">{{ $todayStatus['badge'] }}</x-filament::badge>
                    @endif
                    <span class="tala-how-to-pay__cash-footer-hours tala-where-to-pay__cash-footer-hours">
                        @if ($cashLocation !== '')
                            <strong>{{ $cashLocation }}</strong>
                        @endif
                        @if ($cashHours !== '')
                            <span>{{ $cashLocation !== '' ? ' · ' : '' }}{{ $cashHours }}</span>
                        @endif
                    </span>
                </div>
                <p class="tala-how-to-pay__cash-footer-note tala-where-to-pay__cash-footer-note">
                    Paying in person? Campus Cashier records onsite payments directly: no receipt upload needed.
                </p>
            </div>
        @else
            <article class="tala-channel-card tala-channel-card--cash">
                <header class="tala-channel-card__head">
                    <span class="tala-workspace-card__icon tala-channel-card__icon tala-channel-card__icon--neutral" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-banknotes" />
                    </span>
                    <div class="tala-channel-card__heading-wrap">
                        <{{ $cardHeadingTag }} class="tala-channel-card__title">{{ $cashLabel }}</{{ $cardHeadingTag }}>
                    </div>
                    @if ($resolvedVariant !== 'compact' || $cashFallbackActive)
                        <div class="tala-channel-card__badges">
                            @if ($resolvedVariant !== 'compact')
                                <span class="tala-channel-card__mode-pill">Onsite · No upload</span>
                            @endif
                            @if ($cashFallbackActive)
                                <x-filament::badge color="warning" size="sm">Fallback</x-filament::badge>
                            @endif
                        </div>
                    @endif
                </header>

                <div @class([
                    'tala-cashier-body',
                    'tala-cashier-body--split' => in_array($resolvedVariant, ['page', 'preview'], true) && $calendarMonths !== [],
                ])>
                    <div class="tala-cashier-details">
                        @if ($todayStatus !== null)
                            @php
                                $todayIcon = match ($todayTone) {
                                    'success' => 'heroicon-o-check-circle',
                                    'warning' => 'heroicon-o-clock',
                                    default => 'heroicon-o-x-circle',
                                };
                                $todayDetail = trim((string) ($todayStatus['detail'] ?? ''));
                            @endphp
                            <div @class(['tala-cashier-status', 'tala-cashier-status--'.$todayTone])>
                                <div class="tala-cashier-status__kicker">
                                    <x-filament::icon :icon="$todayIcon" aria-hidden="true" />
                                    <span>{{ $todayStatus['badge'] }}</span>
                                </div>
                                @if ($todayDetail !== '')
                                    <p class="tala-cashier-status__detail">{{ $todayDetail }}</p>
                                @endif
                            </div>
                        @endif

                        @php
                            $showCashLocation = $cashLocation !== '' || $resolvedVariant === 'preview';
                            $showCashHours = $weeklyRows !== [] || $cashHours !== '';
                            $showCashCutoff = $cutoffNote !== '';
                        @endphp
                        @if ($showCashLocation || $showCashHours || $showCashCutoff)
                            <dl class="tala-cashier-facts-group">
                                @if ($showCashLocation)
                                    <div class="tala-cashier-facts-group__row">
                                        <dt>
                                            <x-filament::icon icon="heroicon-o-map-pin" aria-hidden="true" />
                                            <span>Location</span>
                                        </dt>
                                        <dd @class(['tala-channel-facts__empty' => $cashLocation === ''])>
                                            {{ $cashLocation !== '' ? $cashLocation : 'Not set yet' }}
                                        </dd>
                                    </div>
                                @endif

                                @if ($weeklyRows !== [])
                                    <div class="tala-cashier-facts-group__row">
                                        <dt>
                                            <x-filament::icon icon="heroicon-o-clock" aria-hidden="true" />
                                            <span>Weekly hours</span>
                                        </dt>
                                        <dd>
                                            <ul class="tala-cashier-weekly-list" role="list">
                                                @foreach ($weeklyRows as $row)
                                                    @php
                                                        $isClosedRow = strtolower(trim((string) ($row['hours'] ?? ''))) === 'closed';
                                                    @endphp
                                                    <li @class([
                                                        'tala-cashier-weekly-row',
                                                        'is-closed' => $isClosedRow,
                                                    ])>
                                                        <span class="tala-cashier-weekly-row__days">{{ $row['days'] }}</span>
                                                        @if ($isClosedRow)
                                                            <span class="tala-cashier-weekly-row__closed">{{ $row['hours'] }}</span>
                                                        @else
                                                            <span class="tala-cashier-weekly-row__hours">{{ $row['hours'] }}</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </dd>
                                    </div>
                                @elseif ($cashHours !== '')
                                    <div class="tala-cashier-facts-group__row">
                                        <dt>
                                            <x-filament::icon icon="heroicon-o-clock" aria-hidden="true" />
                                            <span>Hours</span>
                                        </dt>
                                        <dd>{{ $cashHours }}</dd>
                                    </div>
                                @endif

                                @if ($showCashCutoff)
                                    <div class="tala-cashier-facts-group__row">
                                        <dt>
                                            <x-filament::icon icon="heroicon-o-information-circle" aria-hidden="true" />
                                            <span>Break &amp; cutoff</span>
                                        </dt>
                                        <dd>{{ $cutoffNote }}</dd>
                                    </div>
                                @endif
                            </dl>
                        @endif

                        <footer class="tala-channel-card__footer">
                            @if ($cashInstructions !== '')
                                <p class="tala-channel-card__instructions">{{ $cashInstructions }}</p>
                            @endif

                            <p class="tala-channel-card__onsite-callout">
                                No receipt upload needed: cashier posts onsite payments directly.
                            </p>
                        </footer>
                    </div>

                    @if ($calendarMonths !== [] && in_array($resolvedVariant, ['page', 'preview'], true))
                        <div class="tala-cashier-calendar-pane">
                            <div
                                class="tala-cashier-calendar"
                                wire:key="cashier-calendar-{{ $resolvedVariant }}-{{ $calendarKey }}"
                                x-data="{
                                    months: @js($calendarMonths),
                                    monthIndex: 0,
                                    selectedDate: null,
                                    init() {
                                        this.selectDefaultForMonth(0);
                                    },
                                    currentMonth() {
                                        return this.months[this.monthIndex] ?? null;
                                    },
                                    flatCells() {
                                        const month = this.currentMonth();
                                        return month ? month.weeks.flat() : [];
                                    },
                                    selectDefaultForMonth(index) {
                                        this.monthIndex = index;
                                        const inMonth = this.flatCells().filter((cell) => cell.in_month);
                                        const today = inMonth.find((cell) => cell.is_today);
                                        this.selectedDate = (today ?? inMonth[0] ?? null)?.date ?? null;
                                    },
                                    prevMonth() {
                                        if (this.monthIndex > 0) {
                                            this.selectDefaultForMonth(this.monthIndex - 1);
                                        }
                                    },
                                    nextMonth() {
                                        if (this.monthIndex < this.months.length - 1) {
                                            this.selectDefaultForMonth(this.monthIndex + 1);
                                        }
                                    },
                                    selectCell(cell) {
                                        if (cell && cell.in_month) {
                                            this.selectedDate = cell.date;
                                        }
                                    },
                                    selectedCell() {
                                        const inMonth = this.flatCells().filter((cell) => cell.in_month);
                                        return inMonth.find((cell) => cell.date === this.selectedDate) ?? inMonth[0] ?? null;
                                    }
                                }"
                            >
                                <div class="tala-cashier-calendar__bar">
                                    <span class="tala-cashier-calendar__month" x-text="currentMonth()?.month_label ?? ''">
                                        {{ $calendarMonths[0]['month_label'] ?? '' }}
                                    </span>
                                    <div class="tala-cashier-calendar__nav-group">
                                        <button
                                            type="button"
                                            class="tala-cashier-calendar__nav"
                                            x-on:click="prevMonth()"
                                            :disabled="monthIndex <= 0"
                                            aria-label="Previous month"
                                        >
                                            <x-filament::icon icon="heroicon-o-chevron-left" aria-hidden="true" />
                                        </button>
                                        <button
                                            type="button"
                                            class="tala-cashier-calendar__nav"
                                            x-on:click="nextMonth()"
                                            :disabled="monthIndex >= months.length - 1"
                                            aria-label="Next month"
                                        >
                                            <x-filament::icon icon="heroicon-o-chevron-right" aria-hidden="true" />
                                        </button>
                                    </div>
                                </div>

                                <div class="tala-cashier-calendar__weekdays" aria-hidden="true">
                                    <span>Mon</span>
                                    <span>Tue</span>
                                    <span>Wed</span>
                                    <span>Thu</span>
                                    <span>Fri</span>
                                    <span>Sat</span>
                                    <span>Sun</span>
                                </div>

                                <div class="tala-cashier-calendar__grid" role="group" aria-label="Cashier monthly operating calendar">
                                    <template x-for="cell in flatCells()" :key="cell.date">
                                        <div>
                                            <template x-if="cell.in_month">
                                                <button
                                                    type="button"
                                                    class="tala-cashier-calendar__day"
                                                    :class="{
                                                        'tala-cashier-calendar__day--open': cell.state === 'open',
                                                        'tala-cashier-calendar__day--closed': cell.state === 'closed' || cell.state === 'holiday',
                                                        'tala-cashier-calendar__day--custom': cell.state === 'custom_hours',
                                                        'tala-cashier-calendar__day--holiday': cell.state === 'holiday',
                                                        'is-today': cell.is_today,
                                                        'is-selected': selectedDate === cell.date
                                                    }"
                                                    x-on:click="selectCell(cell)"
                                                    x-on:focus="selectCell(cell)"
                                                    :aria-label="cell.summary"
                                                    :aria-pressed="selectedDate === cell.date ? 'true' : 'false'"
                                                    :aria-current="cell.is_today ? 'date' : null"
                                                >
                                                    <span class="tala-cashier-calendar__num" x-text="cell.day"></span>
                                                    <span
                                                        class="tala-cashier-calendar__marker"
                                                        :class="'tala-cashier-calendar__marker--' + cell.state"
                                                        x-show="cell.state === 'custom_hours' || cell.state === 'holiday'"
                                                        aria-hidden="true"
                                                    ></span>
                                                </button>
                                            </template>
                                            <template x-if="!cell.in_month">
                                                <span class="tala-cashier-calendar__day tala-cashier-calendar__day--outside" aria-hidden="true" x-text="cell.day"></span>
                                            </template>
                                        </div>
                                    </template>
                                </div>

                                <div class="tala-cashier-calendar__readout" role="status" aria-live="polite">
                                    <span
                                        class="tala-cashier-calendar__readout-dot"
                                        :data-state="selectedCell()?.state ?? 'closed'"
                                        aria-hidden="true"
                                    ></span>
                                    <span class="tala-cashier-calendar__readout-text" x-text="selectedCell()?.summary ?? ''"></span>
                                </div>

                                <ul class="tala-cashier-calendar__legend" aria-label="Calendar key">
                                    <li><span class="tala-cashier-calendar__swatch tala-cashier-calendar__swatch--open" aria-hidden="true"></span>Open</li>
                                    <li><span class="tala-cashier-calendar__swatch tala-cashier-calendar__swatch--modified" aria-hidden="true"></span>Special hours</li>
                                    <li><span class="tala-cashier-calendar__swatch tala-cashier-calendar__swatch--closed" aria-hidden="true"></span>Closed</li>
                                </ul>
                            </div>

                            @if ($upcomingExceptions !== [])
                                <div class="tala-cashier-exceptions">
                                    <p class="tala-cashier-exceptions__title">Upcoming holidays &amp; special hours</p>
                                    <ul class="tala-cashier-exceptions__list">
                                        @foreach (array_slice($upcomingExceptions, 0, 5) as $exception)
                                            <li class="tala-cashier-exceptions__item">
                                                <span class="tala-cashier-exceptions__date">{{ $exception['date_label'] }}</span>
                                                <span class="tala-cashier-exceptions__meta">
                                                    <strong>{{ $exception['label'] }}</strong>
                                                    <span>
                                                        {{ ($exception['status'] ?? '') === 'custom_hours' && ! empty($exception['hours_label']) ? $exception['hours_label'] : 'Closed' }}
                                                    </span>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </article>
        @endif
    @endif
</{{ $rootTag }}>
