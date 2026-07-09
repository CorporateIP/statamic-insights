<?php

use CorporateIp\Insights\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('insights', [DashboardController::class, 'index'])->name('insights.dashboard');
