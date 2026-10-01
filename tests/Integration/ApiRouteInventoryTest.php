<?php

namespace Tests\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiRouteInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_api_route_is_reachable_and_protected_or_validated(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => Str::startsWith($route->uri(), 'api/v1/'));

        $this->assertNotEmpty($routes, 'Expected versioned API routes to be registered.');

        foreach ($routes as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $response = $this->json($method, '/'.$this->concreteUri($route->uri()));
                $description = sprintf('%s /%s', $method, $route->uri());

                $this->assertNotSame(404, $response->getStatusCode(), "Route was not reached: {$description}");

                if ($this->requiresAuthentication($route)) {
                    $this->assertSame(401, $response->getStatusCode(), "Expected authentication guard: {$description}");
                } else {
                    $this->assertContains($response->getStatusCode(), [200, 201, 204, 401, 422, 429, 503], "Unexpected public-route response: {$description}");
                }
            }
        }
    }

    private function requiresAuthentication(Route $route): bool
    {
        return in_array('auth:sanctum', $route->gatherMiddleware(), true);
    }

    private function concreteUri(string $uri): string
    {
        return preg_replace_callback('/\{([^}]+)\}/', function (array $matches): string {
            return $matches[1] === 'provider' ? 'paymongo' : '1';
        }, $uri);
    }
}
