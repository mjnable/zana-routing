<?php

declare(strict_types=1);

namespace Zana\Routing\Inspection;

use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\Severity;
use Zana\Routing\Route;
use Zana\Routing\Router;

/**
 * Every route's handler and middleware can be resolved (FE-46): the class
 * exists, the method exists, and the container has a registration for it.
 * Otherwise the route fails only when someone opens the page.
 */
final readonly class RouteHandlersCheck implements ApplicationCheck
{
    public function __construct(private Router $router)
    {
    }

    public function findings(ApplicationInterface $application): array
    {
        $container = $application->container();
        $findings = [];
        foreach ($this->router->routes() as $route) {
            $where = implode('|', $route->methods) . ' ' . $route->path;
            if (!$route->handler instanceof \Closure) {
                [$class, $method] = is_array($route->handler) ? [$route->handler[0], $route->handler[1]] : [$route->handler, '__invoke'];
                $finding = self::problem($container->has($class), $class, $method, "{$where}: its handler", true);
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
            foreach ($route->getMiddleware() as $middleware) {
                if (is_string($middleware)) {
                    $finding = self::problem($container->has($middleware), $middleware, null, "{$where}: its middleware", false);
                    if ($finding !== null) {
                        $findings[] = $finding;
                    }
                }
            }
        }

        return $findings;
    }

    private static function problem(bool $registered, string $class, ?string $method, string $what, bool $handler): ?Finding
    {
        if (!class_exists($class)) {
            return new Finding('routing.class', Severity::Error, "{$what} {$class} does not exist.", hint: 'Check the class name and its namespace, or remove the route.');
        }
        $file = (new \ReflectionClass($class))->getFileName();
        $file = $file === false ? null : $file;
        if ($method !== null && !method_exists($class, $method)) {
            return new Finding('routing.method', Severity::Error, "{$what} {$class}::{$method}() does not exist.", $file, hint: 'Add the method, or route to an existing one.');
        }
        if (!$registered) {
            $kind = $handler ? 'transient' : 'scoped';

            return new Finding(
                $handler ? 'routing.handler' : 'routing.middleware',
                Severity::Error,
                "{$what} {$class} is not registered in the container.",
                $file,
                hint: "Register it in its module's register(): \$services->{$kind}(\\{$class}::class);",
            );
        }

        return null;
    }
}
