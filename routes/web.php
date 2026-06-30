<?php

use App\Http\Controllers\BillingSlipController;
use App\Http\Controllers\CorPrintController;
use App\Http\Controllers\FinanceStatementController;
use App\Http\Controllers\PaymentAcknowledgementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/outputs/cor/{enrollment}', CorPrintController::class)
    ->middleware('auth')
    ->name('cor.print');

Route::middleware('auth')->group(function (): void {
    Route::get('/outputs/finance/statement/{assessment}', FinanceStatementController::class)
        ->name('finance.statement');
    Route::get('/outputs/finance/billing-slip/{assessment}', BillingSlipController::class)
        ->name('finance.billing-slip');
    Route::get('/outputs/finance/payment-acknowledgement/{payment}', PaymentAcknowledgementController::class)
        ->name('finance.payments.acknowledgement');
});
