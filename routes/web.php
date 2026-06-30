<?php

use App\Http\Controllers\CorPrintController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/outputs/cor/{enrollment}', CorPrintController::class)
    ->middleware('auth')
    ->name('cor.print');
