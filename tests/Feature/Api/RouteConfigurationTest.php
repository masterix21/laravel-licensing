<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

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
