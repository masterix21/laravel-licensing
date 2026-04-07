<?php

use Illuminate\Support\Facades\Route;
use LucaLongo\Licensing\Http\Controllers\Api\HealthController;
use LucaLongo\Licensing\Http\Controllers\Api\LicenseController;
use LucaLongo\Licensing\Http\Controllers\Api\TokenController;
use LucaLongo\Licensing\Http\Controllers\Api\UsageController;

Route::prefix(config('licensing.api.prefix', 'api/licensing/v1'))
    ->middleware(config('licensing.api.middleware', ['api']))
    ->group(function () {
        Route::get('health', [HealthController::class, 'show'])->name('licensing.health');
        Route::post('activate', [LicenseController::class, 'activate'])->name('licensing.activate')->middleware('throttle:licensing-register');
        Route::post('deactivate', [LicenseController::class, 'deactivate'])->name('licensing.deactivate')->middleware('throttle:licensing-register');
        Route::post('refresh', [LicenseController::class, 'refresh'])->name('licensing.refresh')->middleware('throttle:licensing-token');
        Route::post('validate', [LicenseController::class, 'validateLicense'])->name('licensing.validate')->middleware('throttle:licensing-validate');
        Route::post('heartbeat', [UsageController::class, 'heartbeat'])->name('licensing.heartbeat')->middleware('throttle:licensing-validate');
        Route::post('licenses/show', [LicenseController::class, 'show'])->name('licensing.licenses.show')->middleware('throttle:licensing-validate');
        Route::post('token', [TokenController::class, 'issue'])->name('licensing.token.issue')->middleware('throttle:licensing-token');
    });
