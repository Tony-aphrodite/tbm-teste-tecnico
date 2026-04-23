<?php

use App\Http\Controllers\Api\V1\CheckinController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:60,1'])
    ->prefix('v1')
    ->group(function () {
        Route::post('checkin', [CheckinController::class, 'store'])
            ->name('api.v1.checkin.store');
    });
