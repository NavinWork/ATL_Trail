<?php

use App\Http\Controllers\MerchantDashboardController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::post('/usage', [UsageController::class, 'store'])
    ->middleware('throttle:usage');

Route::get('/merchants/{id}/dashboard', [MerchantDashboardController::class, 'show']);

// Scope 2: Customers subscribe to a merchant's plan
Route::post('/subscriptions', [SubscriptionController::class, 'store']);
Route::post('/subscriptions/{id}/change-plan', [SubscriptionController::class, 'changePlan']);
Route::get('/subscriptions/{id}', [SubscriptionController::class, 'show']);
