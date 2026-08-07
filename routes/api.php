<?php

use App\Http\Controllers\Api\RegionScoreApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Third-party read API
|--------------------------------------------------------------------------
|
| Token-authenticated (Sanctum personal access tokens — issue one via
| `php artisan tinker` -> $user->createToken('agency-name')->plainTextToken, or build an
| issuance screen if this goes further). See docs/INGESTION_GUIDE.md for the full contract.
|
*/
Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('/indices', [RegionScoreApiController::class, 'indices']);
    Route::get('/regions', [RegionScoreApiController::class, 'regions']);
    Route::get('/indices/{indexCode}/scores', [RegionScoreApiController::class, 'latestByIndex']);
    Route::get('/regions/{region}/scores', [RegionScoreApiController::class, 'history']);
});
