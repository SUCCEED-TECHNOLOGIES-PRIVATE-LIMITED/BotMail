<?php

use App\Http\Controllers\Webhook\CloudflareWebhookController;
use App\Http\Controllers\Webhook\ResendWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::post('/webhook/cloudflare', CloudflareWebhookController::class);
Route::post('/webhook/resend', ResendWebhookController::class);
