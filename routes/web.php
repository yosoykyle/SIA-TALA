<?php

use App\Http\Controllers\CorVerificationController;
use App\Http\Controllers\FinanceStatementController;
use App\Http\Controllers\PaymentAcknowledgementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/cor/verify/{token}', CorVerificationController::class)->name('cor.verify');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/finance/statements/enrollments/{enrollment}', FinanceStatementController::class)
        ->name('finance.statements.show');
    Route::get('/finance/payments/{payment}/acknowledgement', PaymentAcknowledgementController::class)
        ->name('finance.payments.acknowledgement');
});

Route::livewire('/faq', 'pages::faq')->name('faq');

Route::prefix('student')->name('student.')->middleware(['auth', 'verified', 'student.active'])->group(function () {
    Route::livewire('/dashboard', 'pages::student-hub.dashboard')->name('dashboard');
    Route::livewire('/schedule', 'pages::student-hub.schedule')->name('schedule');
    Route::livewire('/grades', 'pages::student-hub.grades')->name('grades');
    Route::livewire('/financials', 'pages::student-hub.financials')->name('financials');
    Route::livewire('/help', 'pages::student-hub.help')->name('help');
});
