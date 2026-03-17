<?php

use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OddSettingController;
use App\Http\Controllers\Api\V1\WalletBankInfoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me/bank-info', [WalletBankInfoController::class, 'show']);
        Route::post('/me/bank-info', [WalletBankInfoController::class, 'store']);
        Route::put('/me/bank-info', [WalletBankInfoController::class, 'update']);
        Route::delete('/me/bank-info', [WalletBankInfoController::class, 'destroy']);
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);
        Route::get('/odd-settings', [OddSettingController::class, 'index']);
        Route::get('/odd-settings/{oddSetting}', [OddSettingController::class, 'show']);

        Route::prefix('admin')
            ->middleware('role:admin,sanctum')
            ->group(function () {
                Route::get('/dashboard', AdminDashboardController::class);
                Route::post('/announcements', [AnnouncementController::class, 'store']);
                Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update']);
                Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
                Route::post('/odd-settings', [OddSettingController::class, 'store']);
                Route::put('/odd-settings/{oddSetting}', [OddSettingController::class, 'update']);
                Route::delete('/odd-settings/{oddSetting}', [OddSettingController::class, 'destroy']);
            });
    });
});
