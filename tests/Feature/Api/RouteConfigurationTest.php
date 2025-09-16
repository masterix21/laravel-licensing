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

    $validateRoute = routeByName('licensing.validate');
    expect($validateRoute->uri())->toBe($prefix.'/validate')
        ->and(collect($validateRoute->gatherMiddleware()))
        ->toContain('api')
        ->toContain('throttle:api');

    $tokenRoute = routeByName('licensing.token.issue');
    expect($tokenRoute->uri())->toBe($prefix.'/token');

    $registerRoute = routeByName('licensing.usages.register');
    expect($registerRoute->uri())->toBe($prefix.'/licenses/{license}/usages/register');

    expect(RouteFacade::has('licensing.jwks'))->toBeFalse();
});
