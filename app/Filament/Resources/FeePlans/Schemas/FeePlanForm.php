<?php

namespace App\Filament\Resources\FeePlans\Schemas;

use App\Models\Program;
use App\Models\Term;
use App\Support\DecimalMoney;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Support\Str;

class FeePlanForm
{
    public static function components(bool $includeScope = false): array
    {
        return [
            View::make('filament.admin.fee-plans.styles')->columnSpanFull(),
            ...($includeScope ? [Grid::make(2)->schema([
                Select::make('program_id')->label('Program')->options(fn (): array => Program::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Program $program): array => [$program->id => $program->name.' · '.$program->code])->all())->required()->searchable(),
                Select::make('term_id')->label('Term')->options(fn (): array => Term::query()->orderByDesc('starts_on')->pluck('label', 'id')->all())->required()->searchable(),
            ])->columnSpanFull()] : []),
            Hidden::make('reviewed_content_fingerprint'),
            SchemaSection::make('Charges')
                ->description('Add each fixed charge for this program and Term.')
                ->contained(false)
                ->extraAttributes(['class' => 'accounting-finance-form-section'])
                ->schema([
                    Repeater::make('charges')->label('Charges')->hiddenLabel()->schema([
                        Hidden::make('code')->default(fn (): string => 'CHG-'.Str::uuid()),
                        Hidden::make('category'),
                        TextInput::make('label')->label('Charge name')->required()->maxLength(255),
                        self::amount(),
                    ])->minItems(1)->defaultItems(1)->columns(2)->reorderableWithButtons()->reorderableWithDragAndDrop(false)->addActionLabel('Add charge')->live(onBlur: true),
                ])->columnSpanFull(),
            SchemaSection::make('Payment dates')
                ->description('The first payment is required for enrollment. Later payments remain due on their dates. Dates use Asia/Manila.')
                ->contained(false)
                ->extraAttributes(['class' => 'accounting-finance-form-section'])
                ->schema([
                    Repeater::make('obligations')->label('Payment dates')->hiddenLabel()->schema([
                        Hidden::make('code')->default(fn (): string => 'PAY-'.Str::uuid()),
                        Hidden::make('purpose'),
                        Hidden::make('required_for_enrollment'),
                        TextInput::make('label')->label('Payment name')->required()->maxLength(255),
                        self::amount(),
                        DateTimePicker::make('due_at')->label('Due date and time')->required()->seconds(false)->timezone('Asia/Manila'),
                    ])->default([['label' => 'Enrollment payment', 'purpose' => 'Enrollment', 'required_for_enrollment' => true]])
                        ->minItems(1)->columns(3)->reorderableWithButtons()->reorderableWithDragAndDrop(false)->addActionLabel('Add payment date')->live(onBlur: true),
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
}
