<?php

use CorporateIp\Insights\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('insights', [DashboardController::class, 'index'])->name('insights.dashboard');
Route::get('insights/data', [DashboardController::class, 'data'])->name('insights.data');
