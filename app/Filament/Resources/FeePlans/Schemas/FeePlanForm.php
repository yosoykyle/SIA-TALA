<?php

namespace App\Filament\Resources\FeePlans\Schemas;

use App\Actions\Finance\AccountingDisplay;
use App\Models\Program;
use App\Models\Term;
use App\Support\DecimalMoney;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class FeePlanForm
{
    public static function components(bool $includeScope = false, ?int $includeTermId = null): array
    {
        return [
            View::make('filament.admin.fee-plans.styles')->columnSpanFull(),
            ...($includeScope ? [Grid::make()->gridContainer()->columns(['default' => 1, '@md' => 2])->schema([
                Select::make('program_id')->label('Program')->options(fn (): array => Program::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Program $program): array => [$program->id => $program->name.' · '.$program->code])->all())->required()->searchable(),
                Select::make('term_id')->label('Term')->options(fn (): array => self::termOptions($includeTermId))->required()->searchable()->live(),
            ])->columnSpanFull()] : []),
            Hidden::make('reviewed_content_fingerprint'),
            SchemaSection::make('Charges')
                ->description('Add each fixed charge for this program and term.')
                ->contained(false)
                ->extraAttributes(['class' => 'accounting-finance-form-section'])
                ->schema([
                    self::tuitionCalculatorSection(includeCode: true, unitsEditableHint: 'Optional helper — automatically updates the Tuition charge below. Clear Rate or Units to edit Tuition manually.'),
                    Repeater::make('charges')->label('Charges')->hiddenLabel()->schema([
                        Hidden::make('code')->default(fn (): string => 'CHG-'.Str::uuid()),
                        Hidden::make('category'),
                        TextInput::make('label')->label('Charge name')->required()->maxLength(255),
                        self::amount(
                            readOnly: fn (Get $get): bool => self::isCalculatorDrivenTuitionRow($get),
                            helperText: fn (Get $get): ?string => self::isCalculatorDrivenTuitionRow($get)
                                ? 'Auto-calculated from the Tuition fee calculator above.'
                                : null,
                        ),
                    ])
                        ->itemLabel(fn (array $state): string => filled($state['label'] ?? null) ? (string) $state['label'] : 'Charge')
                        ->minItems(1)
                        ->defaultItems(1)
                        ->gridContainer()
                        ->columns(['default' => 1, '@sm' => 2])
                        ->reorderableWithButtons(fn (?array $state): bool => count($state ?? []) > 1)
                        ->deletable(fn (?array $state): bool => count($state ?? []) > 1)
                        ->reorderableWithDragAndDrop(false)
                        ->addActionLabel('Add charge')
                        ->addAction(fn (Action $action): Action => $action->icon(Heroicon::OutlinedPlus)->size('sm'))
                        ->live(onBlur: true),
                ])->columnSpanFull(),
            SchemaSection::make('Payment dates')
                ->description(fn (Get $get): string => self::paymentDatesDescription(
                    filled($get('term_id')) ? (int) $get('term_id') : $includeTermId,
                ))
                ->contained(false)
                ->extraAttributes(['class' => 'accounting-finance-form-section'])
                ->schema([
                    Repeater::make('obligations')->label('Payment dates')->hiddenLabel()->schema([
                        Hidden::make('code')->default(fn (): string => 'PAY-'.Str::uuid()),
                        Hidden::make('purpose'),
                        Hidden::make('required_for_enrollment'),
                        TextInput::make('label')->label('Payment name')->required()->maxLength(255),
                        self::amount(),
                        DateTimePicker::make('due_at')->label('Due date and time')->required()->seconds(false)->timezone(config('app.display_timezone', 'Asia/Manila')),
                    ])
                        ->itemLabel(fn (array $state): string => filled($state['label'] ?? null) ? (string) $state['label'] : 'Payment')
                        ->default([['label' => 'Enrollment payment', 'purpose' => 'Enrollment', 'required_for_enrollment' => true]])
                        ->minItems(1)
                        ->gridContainer()
                        ->columns(['default' => 1, '@sm' => 2, '@xl' => 3])
                        ->reorderableWithButtons(fn (?array $state): bool => count($state ?? []) > 1)
                        ->deletable(fn (?array $state): bool => count($state ?? []) > 1)
                        ->reorderableWithDragAndDrop(false)
                        ->addActionLabel('Add payment date')
                        ->addAction(fn (Action $action): Action => $action->icon(Heroicon::OutlinedPlus)->size('sm'))
                        ->live(onBlur: true),
                ])->columnSpanFull(),
            View::make('filament.admin.fee-plans.form-totals')->viewData(fn (Get $get): array => self::totals($get('charges') ?? [], $get('obligations') ?? []))->columnSpanFull(),
        ];
    }

    /** @return array<int, string> */
    public static function termOptions(?int $includeTermId = null): array
    {
        return Term::query()
            ->with('academicYear')
            ->where(function (Builder $query) use ($includeTermId): void {
                $query->where('state', '!=', Term::StateDraft);

                if ($includeTermId !== null) {
                    $query->orWhereKey($includeTermId);
                }
            })
            ->orderByDesc('starts_on')
            ->get()
            ->mapWithKeys(fn (Term $term): array => [
                $term->getKey() => collect([$term->academicYear?->label, $term->label])->filter()->implode(' · '),
            ])
            ->all();
    }

    private static function amount(?Closure $readOnly = null, ?Closure $helperText = null): TextInput
    {
        $input = TextInput::make('amount')
            ->label('Amount')
            ->mask(RawJs::make('$money($input)'))
            ->stripCharacters(',')
            ->numeric()
            ->minValue(0)
            ->step('0.01')
            ->rules(['decimal:0,2'])
            ->prefix('PHP')
            ->extraInputAttributes(['inputmode' => 'decimal'])
            ->required();

        if ($readOnly !== null) {
            $input->readOnly($readOnly);
        }

        if ($helperText !== null) {
            $input->helperText($helperText);
        }

        return $input;
    }

    /** @return array{charges:list<array<string,mixed>>,obligations:list<array<string,mixed>>} */
    public static function rows(array $data): array
    {
        $charges = collect($data['charges'])->values()->map(fn (array $row): array => [
            ...$row,
            'code' => filled($row['code'] ?? null) ? $row['code'] : 'CHG-'.Str::uuid(),
            'amount' => self::normalizeMoneyInput($row['amount'] ?? ''),
        ])->all();
        $obligations = collect($data['obligations'])->values()->map(fn (array $row, int $index): array => [
            ...$row,
            'code' => filled($row['code'] ?? null) ? $row['code'] : 'PAY-'.Str::uuid(),
            'amount' => self::normalizeMoneyInput($row['amount'] ?? ''),
            'purpose' => filled($row['purpose'] ?? null) ? $row['purpose'] : ($index === 0 ? 'Enrollment' : 'TermPayment'),
            'required_for_enrollment' => $index === 0,
        ])->all();

        return compact('charges', 'obligations');
    }

    /** @return array{chargeTotal:?int,paymentTotal:?int} */
    public static function totals(array $charges, array $obligations): array
    {
        $sum = static function (array $rows): ?int {
            if ($rows === [] || collect($rows)->contains(fn (array $row): bool => ! preg_match('/^\d+(\.\d{1,2})?$/', self::normalizeMoneyInput($row['amount'] ?? '')))) {
                return null;
            }

            return collect($rows)->sum(fn (array $row): int => app(DecimalMoney::class)->toCents(self::normalizeMoneyInput($row['amount'] ?? '')));
        };

        return ['chargeTotal' => $sum($charges), 'paymentTotal' => $sum($obligations)];
    }

    public static function tuitionCalculatorSection(
        bool $includeCode = false,
        string $unitsEditableHint = 'Pre-filled with the student’s enrolled units; adjust if needed.',
    ): SchemaSection {
        return SchemaSection::make('Tuition fee calculator')
            ->description($unitsEditableHint)
            ->contained(false)
            ->extraAttributes(['class' => 'accounting-tuition-calculator'])
            ->schema([
                Grid::make()->gridContainer()->columns(['default' => 1, '@sm' => 2])->schema([
                    TextInput::make('tuition_rate_per_unit')
                        ->label('Rate per unit (PHP)')
                        ->mask(RawJs::make('$money($input)'))
                        ->stripCharacters(',')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->prefix('PHP')
                        ->extraInputAttributes(['inputmode' => 'decimal'])
                        ->dehydrated(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::syncTuitionCharge($get, $set, $includeCode)),
                    TextInput::make('tuition_units')
                        ->label('Units')
                        ->numeric()
                        ->minValue(0)
                        ->step(1)
                        ->rules(['integer', 'min:0'])
                        ->validationMessages([
                            'integer' => 'Units must be a whole number (e.g., 18 or 21).',
                        ])
                        ->extraInputAttributes(['inputmode' => 'numeric'])
                        ->dehydrated(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::syncTuitionCharge($get, $set, $includeCode)),
                ]),
                Text::make(fn (Get $get): string => self::tuitionCalculatorSummary($get('tuition_rate_per_unit'), $get('tuition_units')))
                    ->color(fn (Get $get): string => filled($get('tuition_units')) && self::parseWholeUnits($get('tuition_units')) === null ? 'danger' : 'neutral')
                    ->columnSpanFull(),
            ]);
    }

    public static function tuitionCalculatorSummary(mixed $rate, mixed $units): string
    {
        if (filled($units) && self::parseWholeUnits($units) === null) {
            return 'Units must be a whole number (e.g., 18 or 21).';
        }

        $calculated = self::calculateTuitionLine($rate, $units);
        if ($calculated === null) {
            return 'Enter a rate per unit and whole-number units to calculate tuition.';
        }

        return 'Computed tuition: '.AccountingDisplay::money($calculated['amount']).' · '.$calculated['label'];
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $charges
     * @return array<array-key, array<string, mixed>>|null
     */
    public static function applyTuitionToCharges(array $charges, mixed $rate, mixed $units, bool $includeCode = true): ?array
    {
        $calculated = self::calculateTuitionLine($rate, $units);
        if ($calculated === null) {
            return null;
        }

        $existingKey = null;
        foreach ($charges as $key => $row) {
            if (self::isTuitionChargeRow($row['code'] ?? null, $row['label'] ?? null)) {
                $existingKey = $key;
                break;
            }
        }

        if ($existingKey === null && count($charges) === 1) {
            $onlyKey = array_key_first($charges);
            $onlyRow = $charges[$onlyKey] ?? [];
            if (blank($onlyRow['label'] ?? null) && blank($onlyRow['amount'] ?? null)) {
                $existingKey = $onlyKey;
            }
        }

        $targetKey = $existingKey ?? (string) Str::uuid();
        $existingRow = is_array($charges[$targetKey] ?? null) ? $charges[$targetKey] : [];

        $charges[$targetKey] = [
            ...$existingRow,
            ...($includeCode ? ['code' => filled($existingRow['code'] ?? null) ? $existingRow['code'] : 'TUITION'] : []),
            'label' => $calculated['label'],
            'amount' => $calculated['amount'],
        ];

        return $charges;
    }

    public static function formatUnitCount(float $units): string
    {
        $formatted = number_format($units, 2, '.', '');

        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    private static function paymentDatesDescription(?int $termId): string
    {
        $base = 'The first payment is required for enrollment. Later payments remain due on their dates.';

        if ($termId === null) {
            return $base;
        }

        $term = Term::query()->select(['id', 'starts_on', 'ends_on'])->find($termId);
        if (! $term instanceof Term || $term->starts_on === null || $term->ends_on === null) {
            return $base;
        }

        return $base.' Term dates: '.AccountingDisplay::date($term->starts_on).' – '.AccountingDisplay::date($term->ends_on).'.';
    }

    private static function syncTuitionCharge(Get $get, Set $set, bool $includeCode): void
    {
        $applied = self::applyTuitionToCharges(
            $get('charges') ?? [],
            $get('tuition_rate_per_unit'),
            $get('tuition_units'),
            $includeCode,
        );

        if ($applied !== null) {
            $set('charges', $applied);
        }
    }

    private static function isCalculatorDrivenTuitionRow(Get $get): bool
    {
        if (self::calculateTuitionLine($get('../../tuition_rate_per_unit'), $get('../../tuition_units')) === null) {
            return false;
        }

        return self::isTuitionChargeRow($get('code'), $get('label'));
    }

    private static function isTuitionChargeRow(mixed $code, mixed $label): bool
    {
        $normalizedCode = strtoupper(trim((string) ($code ?? '')));
        $normalizedLabel = strtolower(trim((string) ($label ?? '')));

        return $normalizedCode === 'TUITION' || str_starts_with($normalizedLabel, 'tuition');
    }

    private static function normalizeMoneyInput(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return str_replace(',', '', trim((string) $value));
    }

    private static function parseWholeUnits(mixed $units): ?int
    {
        if (is_int($units)) {
            return $units >= 0 ? $units : null;
        }

        if (is_float($units)) {
            return is_finite($units) && $units >= 0 && floor($units) === $units ? (int) $units : null;
        }

        if (! is_string($units)) {
            return null;
        }

        $trimmed = trim($units);
        if (preg_match('/^\d+$/', $trimmed) !== 1) {
            return null;
        }

        return (int) $trimmed;
    }

    /** @return array{label:string,amount:string}|null */
    private static function calculateTuitionLine(mixed $rate, mixed $units): ?array
    {
        $normalizedRate = self::normalizeMoneyInput($rate ?? '');
        $wholeUnits = self::parseWholeUnits($units);
        if (! is_numeric($normalizedRate) || $wholeUnits === null) {
            return null;
        }

        $rateValue = (float) $normalizedRate;
        if ($rateValue < 0) {
            return null;
        }

        $total = round($rateValue * $wholeUnits, 2);
        $unitsLabel = (string) $wholeUnits;
        $rateLabel = number_format($rateValue, 2, '.', ',');

        return [
            'label' => "Tuition Fee ({$unitsLabel} units @ PHP {$rateLabel}/unit)",
            'amount' => number_format($total, 2, '.', ''),
        ];
    }
}
