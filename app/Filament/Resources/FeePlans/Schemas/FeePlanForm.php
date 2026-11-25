<?php

namespace App\Filament\Resources\FeePlans\Schemas;

use App\Actions\Finance\AccountingDisplay;
use App\Models\Program;
use App\Models\Term;
use App\Support\DecimalMoney;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Str;

class FeePlanForm
{
    public static function components(bool $includeScope = false): array
    {
        return [
            View::make('filament.admin.fee-plans.styles')->columnSpanFull(),
            ...($includeScope ? [Grid::make()->gridContainer()->columns(['default' => 1, '@md' => 2])->schema([
                Select::make('program_id')->label('Program')->options(fn (): array => Program::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Program $program): array => [$program->id => $program->name.' · '.$program->code])->all())->required()->searchable(),
                Select::make('term_id')->label('Term')->options(fn (): array => Term::query()->orderByDesc('starts_on')->pluck('label', 'id')->all())->required()->searchable(),
            ])->columnSpanFull()] : []),
            Hidden::make('reviewed_content_fingerprint'),
            SchemaSection::make('Charges')
                ->description('Add each fixed charge for this program and term.')
                ->contained(false)
                ->extraAttributes(['class' => 'accounting-finance-form-section'])
                ->schema([
                    self::tuitionCalculatorSection(includeCode: true, unitsEditableHint: 'Enter standard units and rate per unit to calculate and apply the Tuition charge.'),
                    Repeater::make('charges')->label('Charges')->hiddenLabel()->schema([
                        Hidden::make('code')->default(fn (): string => 'CHG-'.Str::uuid()),
                        Hidden::make('category'),
                        TextInput::make('label')->label('Charge name')->required()->maxLength(255),
                        self::amount(),
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
                        ->live(onBlur: true),
                ])->columnSpanFull(),
            SchemaSection::make('Payment dates')
                ->description('The first payment is required for enrollment. Later payments remain due on their dates.')
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
                        ->live(onBlur: true),
                ])->columnSpanFull(),
            View::make('filament.admin.fee-plans.form-totals')->viewData(fn (Get $get): array => self::totals($get('charges') ?? [], $get('obligations') ?? []))->columnSpanFull(),
        ];
    }

    private static function amount(): TextInput
    {
        return TextInput::make('amount')->label('Amount')->numeric()->minValue(0)->step('0.01')->rules(['decimal:0,2'])->prefix('PHP')->required();
    }

    /** @return array{charges:list<array<string,mixed>>,obligations:list<array<string,mixed>>} */
    public static function rows(array $data): array
    {
        $charges = collect($data['charges'])->values()->map(fn (array $row): array => [
            ...$row, 'code' => filled($row['code'] ?? null) ? $row['code'] : 'CHG-'.Str::uuid(),
        ])->all();
        $obligations = collect($data['obligations'])->values()->map(fn (array $row, int $index): array => [
            ...$row,
            'code' => filled($row['code'] ?? null) ? $row['code'] : 'PAY-'.Str::uuid(),
            'purpose' => filled($row['purpose'] ?? null) ? $row['purpose'] : ($index === 0 ? 'Enrollment' : 'TermPayment'),
            'required_for_enrollment' => $index === 0,
        ])->all();

        return compact('charges', 'obligations');
    }

    /** @return array{chargeTotal:?int,paymentTotal:?int} */
    public static function totals(array $charges, array $obligations): array
    {
        $sum = static function (array $rows): ?int {
            if ($rows === [] || collect($rows)->contains(fn (array $row): bool => ! preg_match('/^\d+(\.\d{1,2})?$/', (string) ($row['amount'] ?? '')))) {
                return null;
            }

            return collect($rows)->sum(fn (array $row): int => app(DecimalMoney::class)->toCents($row['amount']));
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
            ->extraAttributes(['class' => 'accounting-finance-form-section'])
            ->afterHeader([
                Action::make('applyTuitionToCharges')
                    ->label('Apply to Tuition charge')
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->action(function (Get $get, Set $set) use ($includeCode): void {
                        $applied = self::applyTuitionToCharges(
                            $get('charges') ?? [],
                            $get('tuition_rate_per_unit'),
                            $get('tuition_units'),
                            $includeCode,
                        );
                        if ($applied === null) {
                            Notification::make()
                                ->title('Enter rate per unit and units first')
                                ->body('Provide valid non-negative numbers for Rate per unit (PHP) and Units before applying.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $set('charges', $applied);
                    }),
            ])
            ->schema([
                Grid::make()->gridContainer()->columns(['default' => 1, '@sm' => 2])->schema([
                    TextInput::make('tuition_rate_per_unit')
                        ->label('Rate per unit (PHP)')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->prefix('PHP')
                        ->dehydrated(false)
                        ->live(onBlur: true),
                    TextInput::make('tuition_units')
                        ->label('Units')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.5')
                        ->dehydrated(false)
                        ->live(onBlur: true),
                ]),
                Text::make(fn (Get $get): string => self::tuitionCalculatorSummary($get('tuition_rate_per_unit'), $get('tuition_units')))
                    ->color('neutral')
                    ->columnSpanFull(),
            ]);
    }

    public static function tuitionCalculatorSummary(mixed $rate, mixed $units): string
    {
        $calculated = self::calculateTuitionLine($rate, $units);
        if ($calculated === null) {
            return 'Enter a rate per unit and units to calculate tuition.';
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
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $label = strtolower(trim((string) ($row['label'] ?? '')));
            if ($code === 'TUITION' || str_starts_with($label, 'tuition')) {
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

    /** @return array{label:string,amount:string}|null */
    private static function calculateTuitionLine(mixed $rate, mixed $units): ?array
    {
        if (! is_numeric($rate) || ! is_numeric($units)) {
            return null;
        }

        $rateValue = (float) $rate;
        $unitsValue = (float) $units;
        if ($rateValue < 0 || $unitsValue < 0) {
            return null;
        }

        $total = round($rateValue * $unitsValue, 2);
        $unitsLabel = self::formatUnitCount($unitsValue);
        $rateLabel = number_format($rateValue, 2, '.', ',');

        return [
            'label' => "Tuition Fee ({$unitsLabel} units @ PHP {$rateLabel}/unit)",
            'amount' => number_format($total, 2, '.', ''),
        ];
    }
}
