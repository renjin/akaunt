<?php

use App\Http\Controllers\HitPayWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// HMAC-verified in the controller; CSRF-exempt via bootstrap/app.php
Route::post('/webhooks/hitpay/{company:slug}', HitPayWebhookController::class)
    ->name('webhooks.hitpay');
