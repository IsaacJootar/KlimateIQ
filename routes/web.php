<?php

use App\Http\Controllers\Admin\PlatformSettingController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\InAppNotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\ReportRequestController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\ThresholdConfigController;
use App\Http\Controllers\UserPreferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/notifications', [ProfileController::class, 'updateNotifications'])->name('profile.notifications.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::patch('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
    Route::patch('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');

    Route::get('/regions', [RegionController::class, 'index'])->name('regions.index');
    Route::get('/regions/{region}', [RegionController::class, 'show'])->name('regions.show');
    Route::post('/regions/{region}/summary', [RegionController::class, 'generateSummary'])->name('regions.summary');

    Route::get('/thresholds', [ThresholdConfigController::class, 'index'])->name('thresholds.index');
    Route::post('/thresholds', [ThresholdConfigController::class, 'store'])->name('thresholds.store');
    Route::patch('/thresholds/{threshold}/toggle', [ThresholdConfigController::class, 'toggle'])->name('thresholds.toggle');
    Route::delete('/thresholds/{threshold}', [ThresholdConfigController::class, 'destroy'])->name('thresholds.destroy');

    Route::get('/saved-views', [SavedViewController::class, 'index'])->name('saved-views.index');
    Route::post('/saved-views', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::delete('/saved-views/{savedView}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');

    Route::get('/reports', [ReportRequestController::class, 'index'])->name('reports.index');
    Route::post('/reports', [ReportRequestController::class, 'store'])->name('reports.store');
    Route::get('/reports/{reportRequest}/download', [ReportRequestController::class, 'download'])->name('reports.download');

    Route::get('/notifications', [InAppNotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [InAppNotificationController::class, 'markRead'])->name('notifications.read');

    Route::post('/preferences/theme', [UserPreferenceController::class, 'setTheme'])->name('preferences.theme');
});

Route::middleware(['auth', 'platform.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('settings', [PlatformSettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [PlatformSettingController::class, 'update'])->name('settings.update');
});

require __DIR__.'/auth.php';
