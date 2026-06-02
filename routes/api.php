<?php

use App\Http\Controllers\PayMongoWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/paymongo', PayMongoWebhookController::class)
    ->name('webhooks.paymongo');
