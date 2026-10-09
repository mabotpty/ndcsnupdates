<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicController::class, 'index'])->name('home');
Route::get('/news', [PublicController::class, 'news'])->name('news');

// Telegram posts here. The secret in the path plus Telegram's own secret-token
// header both have to match (see TelegramWebhookController).
Route::post('/telegram/webhook/{secret}', TelegramWebhookController::class)->name('telegram.webhook');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [Admin\AuthController::class, 'create'])->name('login');
        Route::post('login', [Admin\AuthController::class, 'store'])->middleware('throttle:10,1');
    });

    Route::middleware('auth')->group(function () {
        Route::post('logout', [Admin\AuthController::class, 'destroy'])->name('logout');

        Route::get('/', [Admin\DashboardController::class, 'index'])->name('dashboard');
        Route::post('level', [Admin\DashboardController::class, 'setLevel'])->name('level');
        Route::put('level/{level}', [Admin\DashboardController::class, 'updateLevel'])->name('level.update');
        Route::post('settings', [Admin\DashboardController::class, 'settings'])->name('settings');

        Route::resource('incidents', Admin\IncidentController::class)->except('show');
        Route::post('incidents/{incident}/status', [Admin\IncidentController::class, 'status'])->name('incidents.status');

        Route::get('report', [Admin\ReportController::class, 'index'])->name('report');
        Route::post('report/groups', [Admin\ReportController::class, 'storeGroup'])->name('report.groups.store');
        Route::put('report/groups/{group}', [Admin\ReportController::class, 'updateGroup'])->name('report.groups.update');
        Route::delete('report/groups/{group}', [Admin\ReportController::class, 'destroyGroup'])->name('report.groups.destroy');
        Route::post('report/groups/{group}/entries', [Admin\ReportController::class, 'storeEntry'])->name('report.entries.store');
        Route::put('report/entries/{entry}', [Admin\ReportController::class, 'updateEntry'])->name('report.entries.update');
        Route::delete('report/entries/{entry}', [Admin\ReportController::class, 'destroyEntry'])->name('report.entries.destroy');

        Route::resource('news', Admin\NewsController::class)->except('show')->parameters(['news' => 'item']);

        Route::get('account', [Admin\AccountController::class, 'edit'])->name('account');
        Route::put('account/password', [Admin\AccountController::class, 'password'])->name('account.password');
        Route::post('account/telegram', [Admin\AccountController::class, 'telegramCode'])->name('account.telegram');
        Route::delete('account/telegram', [Admin\AccountController::class, 'telegramUnlink'])->name('account.telegram.unlink');

        Route::get('users', [Admin\UserController::class, 'index'])->name('users');
        Route::post('users', [Admin\UserController::class, 'store'])->name('users.store');
        Route::delete('users/{user}', [Admin\UserController::class, 'destroy'])->name('users.destroy');
    });
});
