<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailAccountController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\MessageController;
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
        Route::post('/email-accounts', [EmailAccountController::class, 'store']);
        Route::put('/email-accounts/{emailAccount}', [EmailAccountController::class, 'update']);

        Route::get('/email-accounts/{emailAccount}/folders', [FolderController::class, 'index']);
        Route::post('/folders/{folder}/sync', [FolderController::class, 'sync']);
        Route::get('/messages/{message}', [MessageController::class, 'show']);
        Route::patch('/messages/{message}/flags', [MessageController::class, 'updateFlags']);
    });

    // Local-DB-only, no live IMAP call — not subject to the IMAP-facing throttle above.
    Route::get('/folders/{folder}/messages', [MessageController::class, 'index']);
});
