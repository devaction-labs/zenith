<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;

/**
 * @return list<string>
 */
function zenithRouteMethods(RouteInstance $route): array
{
    return array_values(array_filter($route->methods(), 'is_string'));
}

/**
 * @return array<int, RouteInstance>
 */
function zenithMutationRoutes(): array
{
    $mutatingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteInstance $route): bool => str_starts_with($route->getName() ?? '', 'zenith.'))
        ->filter(fn (RouteInstance $route): bool => count(array_intersect(zenithRouteMethods($route), $mutatingMethods)) > 0)
        ->values()
        ->all();
}

function zenithMutationMethod(RouteInstance $route): string
{
    $method = array_values(array_diff(zenithRouteMethods($route), ['HEAD']))[0] ?? null;

    if (! is_string($method)) {
        throw new RuntimeException("Route [{$route->getName()}] has no mutating HTTP method.");
    }

    return $method;
}

/**
 * Route parameter placeholders, keyed first by route name (for parameters
 * whose valid values are enum-constrained and mean different things on
 * different routes, such as "scope"), then by parameter name as a
 * fallback for everything else.
 *
 * @return array<string, string>
 */
function zenithRouteParameterPlaceholders(): array
{
    return [
        'zenith.batches.clear.destroy:scope' => 'incomplete',
        'zenith.jobs.pending.cancel.destroy:scope' => 'ready',
        'job' => '1',
        'batch' => '1',
        'instance' => '1',
        'supervisor' => '1',
        'tag' => '1',
        'queue' => 'default',
        'connection' => 'redis',
        'event' => '1',
        'workflow' => '1',
    ];
}

function zenithConcreteMutationUri(RouteInstance $route): string
{
    $placeholders = zenithRouteParameterPlaceholders();
    $uri = $route->uri();
    $name = $route->getName() ?? '';

    foreach ($route->parameterNames() as $parameter) {
        if (! is_string($parameter)) {
            continue;
        }

        $value = $placeholders[$name.':'.$parameter] ?? $placeholders[$parameter]
            ?? throw new RuntimeException("No placeholder registered for route parameter [{$parameter}] on [{$name}].");

        $uri = preg_replace('/\{'.preg_quote($parameter, '/').'\??\}/', $value, $uri, 1) ?? $uri;
    }

    return '/'.ltrim($uri, '/');
}

it('rejects a cross-origin mutation request on every Zenith mutation route', function (): void {
    foreach (zenithMutationRoutes() as $route) {
        $method = zenithMutationMethod($route);
        $uri = zenithConcreteMutationUri($route);

        $response = $this->call($method, $uri, server: [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        expect($response->getStatusCode())
            ->toBe(419, "Expected {$method} {$uri} ({$route->getName()}) to reject a forged cross-origin request with 419, got {$response->getStatusCode()}.");
    }
});

it('lets a same-origin mutation request past the request-forgery gate on every Zenith mutation route', function (): void {
    foreach (zenithMutationRoutes() as $route) {
        $method = zenithMutationMethod($route);
        $uri = zenithConcreteMutationUri($route);

        $response = $this->call($method, $uri, server: [
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);

        expect($response->getStatusCode())
            ->not->toBe(419, "Expected {$method} {$uri} ({$route->getName()}) to pass request-forgery protection for a same-origin request, got 419.");
    }
});

it('lets a request carrying a valid CSRF token past the request-forgery gate', function (): void {
    $this->withSession([]);

    $token = csrf_token();

    $response = $this->call('POST', '/horizon/instances/terminate', parameters: [
        '_token' => $token,
    ]);

    expect($response->getStatusCode())->not->toBe(419);
});

it('rejects a request with neither a valid origin nor a matching CSRF token', function (): void {
    $this->withSession([]);

    $response = $this->call('POST', '/horizon/instances/terminate', parameters: [
        '_token' => 'not-the-real-token',
    ]);

    $response->assertStatus(419);
});
