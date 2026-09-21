<?php

use App\Http\Controllers\MerchantDashboardController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::post('/usage', [UsageController::class, 'store'])
    ->middleware('throttle:usage');

Route::get('/merchants/{id}/dashboard', [MerchantDashboardController::class, 'show']);
