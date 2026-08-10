<?php

use App\Http\Controllers\Api\RegionScoreApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Third-party read API
|--------------------------------------------------------------------------
|
| Token-authenticated (Sanctum personal access tokens — issue/revoke them from
| /admin/api-tokens, or via tinker: $user->createToken('agency-name')->plainTextToken).
| See docs/INGESTION_GUIDE.md for the full contract.
|
| Rate-limited to 60 requests/minute per token (Sanctum ties the 'api' limiter to the
| authenticated user, not the IP, so one noisy integration can't exhaust another's quota) —
| generous for a dashboard-integration read API, cheap insurance against a runaway client.
|
*/
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1')->group(function () {
    Route::get('/indices', [RegionScoreApiController::class, 'indices']);
    Route::get('/regions', [RegionScoreApiController::class, 'regions']);
    Route::get('/indices/{indexCode}/scores', [RegionScoreApiController::class, 'latestByIndex']);
    Route::get('/regions/{region}/scores', [RegionScoreApiController::class, 'history']);
});
