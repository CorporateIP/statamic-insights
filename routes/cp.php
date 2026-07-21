<?php

use CorporateIp\Insights\Http\Controllers\DashboardController;
use CorporateIp\Insights\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('insights', [DashboardController::class, 'index'])->name('insights.dashboard');
Route::get('insights/data', [DashboardController::class, 'data'])->name('insights.data');
Route::get('insights/export', [DashboardController::class, 'export'])->name('insights.export');
Route::get('insights/settings', [SettingsController::class, 'index'])->name('insights.settings');
Route::post('insights/settings', [SettingsController::class, 'save'])->name('insights.settings.save');
