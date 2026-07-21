<?php

use CorporateIp\Insights\Http\Controllers\EventController;
use CorporateIp\Insights\Http\Controllers\HitController;
use CorporateIp\Insights\Http\Controllers\TrackerController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// The beacons carry no CSRF token (and can't: pages may come from the static
// cache, where no session/token exists). CSRF adds nothing for an analytics
// counter - the endpoints only ever append anonymous rows.
Route::post('hit', [HitController::class, 'store'])
    ->middleware('throttle:60,1')
    ->withoutMiddleware([ValidateCsrfToken::class]);

Route::post('event', [EventController::class, 'store'])
    ->middleware('throttle:60,1')
    ->withoutMiddleware([ValidateCsrfToken::class]);

Route::get('tracker.js', TrackerController::class);
