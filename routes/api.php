<?php

use Illuminate\Support\Facades\Route;
use SageGrids\ContinuousDelivery\Http\Controllers\ApprovalController;
use SageGrids\ContinuousDelivery\Http\Controllers\DeployController;
use SageGrids\ContinuousDelivery\Http\Controllers\HealthController;

/*
|--------------------------------------------------------------------------
| GitHub Webhook Route
|--------------------------------------------------------------------------
*/

Route::post(
    config('continuous-delivery.route.path', '/deploy/github'),
    [DeployController::class, 'github']
)->name('continuous-delivery.webhook');

/*
|--------------------------------------------------------------------------
| Deployment Status Route
|--------------------------------------------------------------------------
*/

Route::get(
    '/deploy/status/{uuid}',
    [DeployController::class, 'status']
)->name('continuous-delivery.status');

/*
|--------------------------------------------------------------------------
| Health Check Route
|--------------------------------------------------------------------------
*/

Route::get(
    '/deploy/health',
    [HealthController::class, 'check']
)->name('continuous-delivery.health');

/*
|--------------------------------------------------------------------------
| Approval Routes (token-based, no auth required)
|--------------------------------------------------------------------------
*/

Route::middleware(['throttle:cd-approval'])->group(function () {
    Route::get(
        '/deploy/approve/{token}',
        [ApprovalController::class, 'approve']
    )->name('continuous-delivery.approve');

    Route::get(
        '/deploy/reject/{token}',
        [ApprovalController::class, 'reject']
    )->name('continuous-delivery.reject');
});
