<?php

namespace App\Filament\Resources\PaymentAttempts;

use App\Filament\Resources\PaymentAttempts\Pages\ListPaymentAttempts;
use App\Filament\Resources\PaymentAttempts\Pages\ViewPaymentAttempt;
use App\Filament\Resources\PaymentAttempts\Schemas\PaymentAttemptForm;
use App\Filament\Resources\PaymentAttempts\Schemas\PaymentAttemptInfolist;
use App\Filament\Resources\PaymentAttempts\Tables\PaymentAttemptsTable;
use App\Models\PaymentAttempt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PaymentAttemptResource extends Resource
{
    protected static ?string $model = PaymentAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Accounting';

    protected static ?string $navigationLabel = 'Payment Queue';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (auth()->user()?->hasRole('academic-head')) {
            return 'Academic Head';
        }

        return 'Accounting';
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentAttemptForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentAttemptInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentAttemptsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentAttempts::route('/'),
            'view' => ViewPaymentAttempt::route('/{record}'),
        ];
    }
}
