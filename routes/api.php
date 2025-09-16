<?php

use Illuminate\Support\Facades\Route;
use LucaLongo\Licensing\Http\Controllers\Api\LicenseController;
use LucaLongo\Licensing\Http\Controllers\Api\TokenController;
use LucaLongo\Licensing\Http\Controllers\Api\UsageController;

Route::prefix(config('licensing.api.prefix', 'api/licensing/v1'))
    ->middleware(config('licensing.api.middleware', ['api']))
    ->group(function () {
        Route::post('validate', [LicenseController::class, 'validate'])->name('licensing.validate');
        Route::post('token', [TokenController::class, 'issue'])->name('licensing.token.issue');

        Route::prefix('licenses/{license}')->group(function () {
            Route::post('usages/register', [UsageController::class, 'register'])->name('licensing.usages.register');
            Route::post('usages/heartbeat', [UsageController::class, 'heartbeat'])->name('licensing.usages.heartbeat');
            Route::post('usages/revoke', [UsageController::class, 'revoke'])->name('licensing.usages.revoke');
            Route::post('usages/replace', [UsageController::class, 'replace'])->name('licensing.usages.replace');
        });

        if (config('licensing.offline_token.format') === 'jws') {
            Route::get('jwks.json', [TokenController::class, 'jwks'])->name('licensing.jwks');
        }
    });