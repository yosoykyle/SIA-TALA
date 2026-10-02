<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/filament/components');
Route::view('/public', 'public');
