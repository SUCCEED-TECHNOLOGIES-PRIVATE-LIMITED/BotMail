<?php

use App\Http\Controllers\Api\V0InboxController;
use Illuminate\Support\Facades\Route;

Route::get('/inboxes', [V0InboxController::class, 'index']);
Route::post('/inboxes', [V0InboxController::class, 'store']);
Route::get('/inboxes/{inboxId}', [V0InboxController::class, 'show']);
Route::delete('/inboxes/{inboxId}', [V0InboxController::class, 'destroy']);

Route::get('/inboxes/{inboxId}/messages', [V0InboxController::class, 'messages']);
Route::post('/inboxes/{inboxId}/messages', [V0InboxController::class, 'send']);
Route::post('/inboxes/{inboxId}/messages/send', [V0InboxController::class, 'send']);
Route::get('/inboxes/{inboxId}/messages/{messageId}', [V0InboxController::class, 'message'])->where('messageId', '.*');
Route::post('/inboxes/{inboxId}/messages/{messageId}/reply', [V0InboxController::class, 'reply'])->where('messageId', '.*');
