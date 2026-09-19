<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BulkMessageController;
use App\Http\Controllers\Api\ComposeController;
use App\Http\Controllers\Api\DraftController;
use App\Http\Controllers\Api\EmailAccountController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:6,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());

    Route::get('/email-accounts', [EmailAccountController::class, 'index']);
    Route::delete('/email-accounts/{emailAccount}', [EmailAccountController::class, 'destroy']);

    Route::middleware('throttle:10,1')->group(function () {
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

        Route::post('/email-accounts', [EmailAccountController::class, 'store']);
        Route::put('/email-accounts/{emailAccount}', [EmailAccountController::class, 'update']);

        Route::get('/email-accounts/{emailAccount}/folders', [FolderController::class, 'index']);
        Route::post('/email-accounts/{emailAccount}/folders', [FolderController::class, 'store']);
        Route::patch('/folders/{folder}', [FolderController::class, 'rename']);
        Route::delete('/folders/{folder}', [FolderController::class, 'destroy']);
        Route::post('/folders/{folder}/sync', [FolderController::class, 'sync']);

        // Bulk message actions — registered before the single-message
        // {message} routes below, since "bulk" would otherwise be captured
        // as that route's {message} parameter (both patterns match the same
        // URI shape; Laravel picks whichever is registered first).
        Route::patch('/messages/bulk/flags', [BulkMessageController::class, 'updateFlags']);
        Route::patch('/messages/bulk/move', [BulkMessageController::class, 'move']);
        Route::delete('/messages/bulk', [BulkMessageController::class, 'destroy']);

        Route::get('/messages/{message}', [MessageController::class, 'show']);
        Route::patch('/messages/{message}/flags', [MessageController::class, 'updateFlags']);
        Route::patch('/messages/{message}/move', [MessageController::class, 'move']);
        Route::delete('/messages/{message}', [MessageController::class, 'destroy']);
        Route::post('/email-accounts/{emailAccount}/messages', [ComposeController::class, 'send']);
        Route::post('/email-accounts/{emailAccount}/drafts', [DraftController::class, 'store']);
        Route::put('/drafts/{message}', [DraftController::class, 'update']);
        Route::delete('/folders/{folder}/messages', [FolderController::class, 'emptyTrash']);
    });

    // Local-DB-only, no live IMAP/SMTP call — not subject to the mail-server-facing throttle above.
    Route::get('/folders/{folder}/messages', [MessageController::class, 'index']);
    Route::get('/email-accounts/{emailAccount}/starred-messages', [MessageController::class, 'starred']);
    Route::get('/email-accounts/{emailAccount}/search-messages', [MessageController::class, 'search']);
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download']);
});
