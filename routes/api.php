<?php

use App\Http\Controllers\MatchController;
use App\Http\Controllers\StandingController;
use App\Models\FirearmModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/standings', [StandingController::class, 'apiIndex']);
    Route::get('/matches/upcoming', [MatchController::class, 'apiUpcoming']);
    Route::get('/matches/recent-results', [MatchController::class, 'apiRecentResults']);
    Route::get('/events/calendar', [MatchController::class, 'publicCalendarData']);

    Route::get('/firearm-models', function (Request $request) {
        $query = FirearmModel::active()->orderBy('name');

        if ($makeId = $request->input('make_id')) {
            $query->forMake((int) $makeId);
        }

        return response()->json(['data' => $query->get(['id', 'firearm_make_id', 'name'])]);
    });
});
