<?php

use App\Http\Controllers\MerchantDashboardController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/merchants/1/dashboard');
});

Route::get('/merchants/{id}/dashboard', [MerchantDashboardController::class, 'view']);

// Support direct POST /usage as per brief specification #2
Route::post('/usage', [UsageController::class, 'store'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->middleware('throttle:usage');

// Scope 2: Customers subscribe to a merchant's plan
Route::post('/subscriptions', [SubscriptionController::class, 'store'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::post('/subscriptions/{id}/change-plan', [SubscriptionController::class, 'changePlan'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);

Route::get('/subscriptions/{id}', [SubscriptionController::class, 'show']);
