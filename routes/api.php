<?php

use Illuminate\Support\Facades\Route;
use SageGrids\ContinuousDelivery\Http\Controllers\ApprovalController;
use SageGrids\ContinuousDelivery\Http\Controllers\DeployController;

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
| Approval Routes (token-based, no auth required)
|--------------------------------------------------------------------------
*/

Route::get(
    '/deploy/approve/{token}',
    [ApprovalController::class, 'approve']
)->name('continuous-delivery.approve');

Route::get(
    '/deploy/reject/{token}',
    [ApprovalController::class, 'reject']
)->name('continuous-delivery.reject');
