<?php

use App\Http\Controllers\FirearmReferenceController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\StandingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/standings', [StandingController::class, 'apiIndex']);
    Route::get('/matches/upcoming', [MatchController::class, 'apiUpcoming']);
    Route::get('/matches/recent-results', [MatchController::class, 'apiRecentResults']);
    Route::get('/events/calendar', [MatchController::class, 'publicCalendarData']);

    Route::get('/firearm-makes', [FirearmReferenceController::class, 'searchMakes']);
    Route::get('/firearm-models', [FirearmReferenceController::class, 'searchModels']);
    Route::get('/firearm-calibres', [FirearmReferenceController::class, 'searchCalibres']);
});
