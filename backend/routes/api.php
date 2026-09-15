<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmailAccountController;
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
    });
});
