<?php

use App\Http\Controllers\Api\V1\AdminAnalyticsController;
use App\Http\Controllers\Api\V1\AdminBalanceAdjustmentController;
use App\Http\Controllers\Api\V1\AdminBankSettingController;
use App\Http\Controllers\Api\V1\AdminDashboardController;
use App\Http\Controllers\Api\V1\AdminDepositController;
use App\Http\Controllers\Api\V1\AdminHealthController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AdminWalletController;
use App\Http\Controllers\Api\V1\AdminWithdrawalController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AppSettingController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BetController;
use App\Http\Controllers\Api\V1\BettingDistributionController;
use App\Http\Controllers\Api\V1\DepositController;
use App\Http\Controllers\Api\V1\FcmTokenController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OddSettingController;
use App\Http\Controllers\Api\V1\ThreeDResultController;
use App\Http\Controllers\Api\V1\TwoDResultController;
use App\Http\Controllers\Api\V1\WalletBankInfoController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WalletCurrencyController;
use App\Http\Controllers\Api\V1\WalletTransactionController;
use App\Http\Controllers\Api\V1\WithdrawalController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/app-settings/maintenance', [AppSettingController::class, 'maintenance']);

    Route::middleware(['auth:sanctum', 'not_banned'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me/wallet', [WalletController::class, 'show']);
        Route::put('/me/wallet/currency', [WalletCurrencyController::class, 'set']);
        Route::get('/me/wallet/transactions', [WalletTransactionController::class, 'index']);
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
        Route::get('/closed-numbers', [BettingDistributionController::class, 'getClosedNumbers']);
        Route::prefix('deposits')->controller(DepositController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{deposit}', 'show');
            Route::get('/{deposit}/proof', 'downloadProof')->name('deposits.proof');
            Route::post('/{deposit}/cancel', 'cancel');
        });

        Route::prefix('withdrawals')->controller(WithdrawalController::class)->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{withdrawal}', 'show');
            Route::get('/{withdrawal}/proof', 'downloadProof')->name('withdrawals.proof');
            Route::post('/{withdrawal}/cancel', 'cancel');
        });

        Route::prefix('bets')->controller(BetController::class)->group(function () {
            Route::get('/', 'index');
            Route::get('/accepted-payments', 'acceptedPayments');
            Route::get('/payout-history', 'payoutHistory');
            Route::get('/{bet}', 'show');
            Route::post('/', 'store');
            Route::delete('/{bet}', 'destroy');
        });

        // FCM token management
        Route::prefix('fcm')->controller(FcmTokenController::class)->group(function () {
            Route::post('/token', 'store');
            Route::delete('/token', 'destroy');
            Route::post('/logout-all', 'logoutAll');
            Route::get('/tokens', 'index');
        });

        // Notifications
        Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
            Route::post('/test', 'sendTest');
            Route::get('/logs', 'logs');
            Route::get('/stats', 'stats');
            Route::post('/read-all', 'markAllAsRead');
        });

        Route::prefix('admin')
            ->middleware('role:admin,sanctum')
            ->group(function () {
                Route::post('/notifications/send', [NotificationController::class, 'send']);
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
                Route::get('/bets/{bet}', [BetController::class, 'adminShow']);
                Route::patch('/bets/{bet}/status', [BetController::class, 'updateReviewStatus']);
                // Winning-bet payout approval (bulk route first so it is not shadowed by {bet}).
                Route::post('/bets/payout/bulk', [BetController::class, 'approvePayoutBulk']);
                Route::post('/bets/{bet}/payout', [BetController::class, 'approvePayout']);
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
                Route::prefix('users/{user}')->group(function () {
                    Route::get('/wallet', [AdminWalletController::class, 'show']);
                    Route::get('/wallet/transactions', [AdminWalletController::class, 'transactions']);
                    Route::post('/balance-adjustment', [AdminBalanceAdjustmentController::class, 'store']);
                });
                Route::prefix('deposits')->controller(AdminDepositController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{deposit}', 'show');
                    Route::get('/{deposit}/proof', 'downloadProof');
                    Route::post('/{deposit}/approve', 'approve');
                    Route::post('/{deposit}/reject', 'reject');
                });
                Route::prefix('withdrawals')->controller(AdminWithdrawalController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{withdrawal}', 'show');
                    Route::post('/{withdrawal}/complete', 'complete');
                    Route::post('/{withdrawal}/reject', 'reject');
                });
                Route::prefix('betting-distribution')
                    ->name('admin.betting-distribution.')
                    ->controller(BettingDistributionController::class)
                    ->group(function () {
                        Route::get('/current', 'getCurrentDistribution')->name('current');
                        Route::get('/periods-today', 'getPeriodsForToday')->name('periods-today');
                        Route::post('/adjust-odds', 'adjustOdds')->name('adjust-odds');
                        Route::get('/temp-odds/{date}/{targetOpentime}', 'getTempOdds')->name('temp-odds');
                        Route::post('/reset-odds/{date}/{targetOpentime}', 'resetOdds')->name('reset-odds');
                        Route::post('/number-controls', 'setNumberControls')->name('number-controls.set');
                        Route::post('/number-controls/reopen', 'reopenNumberControls')->name('number-controls.reopen');
                        Route::get('/number-controls/{date}/{targetOpentime}', 'getNumberControls')->name('number-controls.index');
                        Route::get('/{date}/{targetOpentime}', 'getDistributionForPeriod')->name('show');
                    });
            });
    });
});
