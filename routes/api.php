<?php

use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\AdminAnalyticsController;
use App\Http\Controllers\Api\V1\AdminHealthController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AppSettingController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BetController;
use App\Http\Controllers\Api\V1\OddSettingController;
use App\Http\Controllers\Api\V1\TwoDResultController;
use App\Http\Controllers\Api\V1\ThreeDResultController;
use App\Http\Controllers\Api\V1\AdminBankSettingController;
use App\Http\Controllers\Api\V1\WalletBankInfoController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/app-settings/maintenance', [AppSettingController::class, 'maintenance']);

    Route::middleware(['auth:sanctum', 'not_banned'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me/bank-info', [WalletBankInfoController::class, 'show']);
        Route::post('/me/bank-info', [WalletBankInfoController::class, 'store']);
        Route::put('/me/bank-info', [WalletBankInfoController::class, 'update']);
        Route::delete('/me/bank-info', [WalletBankInfoController::class, 'destroy']);
        Route::get('/bank-settings', [AdminBankSettingController::class, 'userIndex']);
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);
        Route::get('/odd-settings', [OddSettingController::class, 'index']);
        Route::get('/odd-settings/{oddSetting}', [OddSettingController::class, 'show']);
        Route::get('/two-d-results', [TwoDResultController::class, 'index']);
        Route::get('/two-d-results/latest', [TwoDResultController::class, 'latest']);
        Route::get('/two-d-results/last-5-days', [TwoDResultController::class, 'lastFiveDays']);
        Route::get('/three-d-results', [ThreeDResultController::class, 'index']);
        Route::get('/three-d-results/latest', [ThreeDResultController::class, 'latest']);
        Route::prefix('bets')->controller(BetController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/accepted-payments', 'acceptedPayments');
            Route::get('/payout-history', 'payoutHistory');
            Route::get('/{bet}/pay-slip', 'downloadPaySlip')->name('bets.pay-slip');
            Route::get('/{bet}/payout-proof', 'downloadPayoutProof')->name('bets.payout-proof');
            Route::get('/{bet}', 'show');
            Route::post('/', 'store');
            Route::delete('/{bet}', 'destroy');
        });

        Route::prefix('admin')
            ->middleware('role:admin,sanctum')
            ->group(function () {
                Route::get('/dashboard', AdminDashboardController::class);
                Route::get('/health/thaistock2d-live', [AdminHealthController::class, 'thaiStock2dLive']);
                Route::put('/app-settings/maintenance', [AppSettingController::class, 'updateMaintenance']);
                Route::post('/announcements', [AnnouncementController::class, 'store']);
                Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update']);
                Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);
                Route::post('/odd-settings', [OddSettingController::class, 'store']);
                Route::put('/odd-settings/{oddSetting}', [OddSettingController::class, 'update']);
                Route::delete('/odd-settings/{oddSetting}', [OddSettingController::class, 'destroy']);
                Route::post('/three-d-results', [ThreeDResultController::class, 'store']);
                Route::put('/three-d-results/{threeDResult}', [ThreeDResultController::class, 'update']);
                Route::delete('/three-d-results/{threeDResult}', [ThreeDResultController::class, 'destroy']);
                Route::prefix('analytics')->controller(AdminAnalyticsController::class)->group(function () {
                    Route::get('/kpis', 'kpis');
                    Route::get('/trends/daily', 'dailyTrends');
                    Route::get('/status-distribution', 'statusDistribution');
                    Route::get('/payouts', 'payouts');
                    Route::get('/top-numbers', 'topNumbers');
                    Route::get('/settlement-runs', 'settlementRuns');
                });
                Route::get('/bets', [BetController::class, 'adminIndex']);
                Route::patch('/bets/{bet}/status', [BetController::class, 'updateReviewStatus']);
                Route::post('/bets/{bet}/payout', [BetController::class, 'payout']);
                Route::post('/bets/{bet}/refund', [BetController::class, 'refund']);
                Route::prefix('bank-settings')->controller(AdminBankSettingController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{adminBankSetting}', 'show');
                    Route::post('/', 'store');
                    Route::put('/{adminBankSetting}', 'update');
                    Route::delete('/{adminBankSetting}', 'destroy');
                });
                Route::prefix('users')->controller(AdminUserController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{user}', 'show');
                    Route::patch('/{user}/role', 'assignRole');
                    Route::post('/{user}/ban', 'ban');
                    Route::post('/{user}/unban', 'unban');
                    Route::delete('/{user}', 'destroy');
                });
            });
    });
});
