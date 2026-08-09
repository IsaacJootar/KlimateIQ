<?php

use App\Http\Controllers\Admin\AgencyManagementController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\PipelineHealthController;
use App\Http\Controllers\Admin\ActionRecommendationController;
use App\Http\Controllers\Admin\PlatformSettingController;
use App\Http\Controllers\Admin\ScoringConfigController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\CoveragePreferenceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InAppNotificationController;
use App\Http\Controllers\PlatformOverviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\ReportRequestController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\ThresholdConfigController;
use App\Http\Controllers\UserPreferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

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

    Route::get('/coverage', [CoveragePreferenceController::class, 'edit'])->name('coverage.edit');
    Route::put('/coverage', [CoveragePreferenceController::class, 'update'])->name('coverage.update');
});

Route::middleware(['auth', 'federal.oversight'])->group(function () {
    Route::get('/overview', [PlatformOverviewController::class, 'index'])->name('overview.index');
});

Route::middleware(['auth', 'platform.admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('settings', [PlatformSettingController::class, 'index'])->name('settings.index');
    Route::put('settings', [PlatformSettingController::class, 'update'])->name('settings.update');

    Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
    Route::patch('users/{user}/toggle-admin', [UserManagementController::class, 'toggleAdmin'])->name('users.toggle-admin');
    Route::patch('users/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->name('users.toggle-active');

    Route::get('agencies', [AgencyManagementController::class, 'index'])->name('agencies.index');
    Route::patch('agencies/{agency}', [AgencyManagementController::class, 'update'])->name('agencies.update');
    Route::patch('agencies/{agency}/toggle-oversight', [AgencyManagementController::class, 'toggleOversight'])->name('agencies.toggle-oversight');
    Route::post('agencies/merge', [AgencyManagementController::class, 'merge'])->name('agencies.merge');

    Route::get('pipeline', [PipelineHealthController::class, 'index'])->name('pipeline.index');
    Route::post('pipeline/run-now', [PipelineHealthController::class, 'runNow'])->name('pipeline.run-now');
    Route::post('pipeline/failures/{uuid}/retry', [PipelineHealthController::class, 'retryFailure'])->name('pipeline.failures.retry');

    Route::get('scoring', [ScoringConfigController::class, 'index'])->name('scoring.index');
    Route::put('scoring/{index}', [ScoringConfigController::class, 'update'])->name('scoring.update');

    Route::get('actions', [ActionRecommendationController::class, 'index'])->name('actions.index');
    Route::put('actions/{index}', [ActionRecommendationController::class, 'update'])->name('actions.update');

    Route::get('api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
});

require __DIR__.'/auth.php';
