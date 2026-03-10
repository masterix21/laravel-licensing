<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as RouteFacade;
use LucaLongo\Licensing\Http\Controllers\Api\HealthController;
use LucaLongo\Licensing\Http\Controllers\Api\LicenseController;
use LucaLongo\Licensing\Http\Controllers\Api\TokenController;
use LucaLongo\Licensing\Http\Controllers\Api\UsageController;

function routeByName(string $name): Route
{
    $route = RouteFacade::getRoutes()->getByName($name);

    expect($route)->not->toBeNull("Route {$name} should be registered");

    return $route;
}

test('license API routes expose expected URIs and middleware', function () {
    if (! RouteFacade::has('licensing.validate')) {
        $routeFile = realpath(__DIR__.'/../../../routes/api.php');
        expect($routeFile)->not->toBeFalse('Package API routes file not found');
        require $routeFile;
        RouteFacade::getRoutes()->refreshNameLookups();
    }

    $prefix = config('licensing.api.prefix');

    $activateRoute = routeByName('licensing.activate');
    expect($activateRoute->uri())->toBe($prefix.'/activate');

    $deactivateRoute = routeByName('licensing.deactivate');
    expect($deactivateRoute->uri())->toBe($prefix.'/deactivate');

    $refreshRoute = routeByName('licensing.refresh');
    expect($refreshRoute->uri())->toBe($prefix.'/refresh');

    $validateRoute = routeByName('licensing.validate');
    expect($validateRoute->uri())->toBe($prefix.'/validate')
        ->and(collect($validateRoute->gatherMiddleware()))
        ->toContain('api');

    $heartbeatRoute = routeByName('licensing.heartbeat');
    expect($heartbeatRoute->uri())->toBe($prefix.'/heartbeat');

    $licenseShowRoute = routeByName('licensing.licenses.show');
    expect($licenseShowRoute->uri())->toBe($prefix.'/licenses/{licenseKey}');

    $healthRoute = routeByName('licensing.health');
    expect($healthRoute->uri())->toBe($prefix.'/health');

    $tokenRoute = routeByName('licensing.token.issue');
    expect($tokenRoute->uri())->toBe($prefix.'/token');
});

// Regression test for issue #4: controller classes were missing in v1.0.3,
// causing "Class does not exist" ReflectionException on `php artisan route:list`.
test('all API route controller classes exist', function () {
    expect(class_exists(LicenseController::class))->toBeTrue();
    expect(class_exists(TokenController::class))->toBeTrue();
    expect(class_exists(UsageController::class))->toBeTrue();
    expect(class_exists(HealthController::class))->toBeTrue();
});

test('route:list does not throw when API routes are enabled', function () {
    $exitCode = Artisan::call('route:list');

    expect($exitCode)->toBe(0);
});
