<?php

namespace Tests\Feature\Auth;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Authorization in this app is entirely middleware-driven -- no policies, no
 * Gate calls, and every FormRequest::authorize() returns true. That makes the
 * single `role:admin,sanctum` group in routes/api.php the only thing standing
 * between a player's token and 80-odd admin actions, and a route drifting
 * outside it would be caught by nothing.
 *
 * The existing authorization tests exercise one endpoint each. This one asserts
 * the property across the whole route table, so the guarantee survives future
 * edits to routes/api.php.
 */
class AdminRouteGuardTest extends TestCase
{
    /**
     * @return list<RoutingRoute>
     */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/v1/admin/')
        ));
    }

    private function hasAdminRoleGuard(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // The alias survives as `role:admin,sanctum`; a cached or resolved
            // stack shows Spatie's class name with the same parameters.
            if (str_contains($middleware, 'role:admin')) {
                return true;
            }

            if (str_contains($middleware, 'RoleMiddleware') && str_contains($middleware, 'admin')) {
                return true;
            }
        }

        return false;
    }

    public function test_the_admin_route_group_is_not_empty(): void
    {
        // Guards the guard: a typo in the prefix would make every assertion
        // below vacuously pass.
        $this->assertGreaterThan(50, count($this->adminRoutes()));
    }

    public function test_every_admin_route_requires_the_admin_role(): void
    {
        $unguarded = [];

        foreach ($this->adminRoutes() as $route) {
            if (! $this->hasAdminRoleGuard($route)) {
                $unguarded[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $unguarded, "Admin routes reachable without the admin role:\n".implode("\n", $unguarded));
    }

    public function test_every_admin_route_requires_authentication(): void
    {
        $unauthenticated = [];

        foreach ($this->adminRoutes() as $route) {
            $stack = implode(' ', array_filter($route->gatherMiddleware(), 'is_string'));

            if (! str_contains($stack, 'auth:sanctum') && ! str_contains($stack, 'Authenticate:sanctum')) {
                $unauthenticated[] = implode('|', $route->methods()).' /'.$route->uri();
            }
        }

        $this->assertSame([], $unauthenticated, "Admin routes reachable without authentication:\n".implode("\n", $unauthenticated));
    }
}
