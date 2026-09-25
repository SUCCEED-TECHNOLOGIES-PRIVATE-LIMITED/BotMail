<?php

use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\MessageController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.key')->scopeBindings()->group(function () {
    Route::get('/inboxes', [InboxController::class, 'index']);
    Route::post('/inboxes', [InboxController::class, 'store']);
    Route::get('/inboxes/{inbox}', [InboxController::class, 'show']);
    Route::delete('/inboxes/{inbox}', [InboxController::class, 'destroy']);

    Route::get('/inboxes/{inbox}/messages', [MessageController::class, 'index']);
    Route::post('/inboxes/{inbox}/messages', [MessageController::class, 'store']);
    Route::get('/inboxes/{inbox}/messages/{message}', [MessageController::class, 'show']);
    Route::delete('/inboxes/{inbox}/messages/{message}', [MessageController::class, 'destroy']);
    Route::post('/inboxes/{inbox}/messages/{message}/reply', [MessageController::class, 'reply']);
});
