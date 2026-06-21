<?php

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

Route::livewire('/faq', 'pages::faq')->name('faq');

Route::prefix('student')->name('student.')->middleware(['auth', 'student.active'])->group(function () {
    Route::livewire('/dashboard', 'pages::student-hub.dashboard')->name('dashboard');
    Route::livewire('/schedule', 'pages::student-hub.schedule')->name('schedule');
    Route::livewire('/grades', 'pages::student-hub.grades')->name('grades');
    Route::livewire('/financials', 'pages::student-hub.financials')->name('financials');
    Route::livewire('/help', 'pages::student-hub.help')->name('help');
});
