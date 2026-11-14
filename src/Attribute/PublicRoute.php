<?php

declare(strict_types=1);

namespace Zana\Routing\Attribute;

use Attribute;
use Zana\Routing\RedirectHandler;
use Zana\Routing\Route;
use Zana\Routing\RoutingException;

/**
 * Anyone may reach this route, for the reason given (2.21, FE-68, ADR-033).
 * Every route says who may reach it: this, or #[RequiresAuthentication] or
 * #[RequiresPermission] from mj-zana/security. `check` reports a route that
 * says nothing. The reason is required, so each exception can be reviewed.
 *
 *     #[PublicRoute(reason: 'The pages of the public website')]
 *     final class PageController { … }                    // on a class: all its methods
 *
 *     $router->group('', $routes, public: 'The public website');   // every route of a group
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final readonly class PublicRoute
{
    /** The route meta key a group's reason is stored under. */
    public const string META = 'public';

    public function __construct(public string $reason)
    {
        if (trim($reason) === '') {
            throw new RoutingException('#[PublicRoute] needs a reason: say why anyone may reach the route.');
        }
    }

    /**
     * Why anyone may reach the route, or null when it does not say it is
     * public: from its group, its handler's method, class or closure, in that
     * order. A redirect is public: the route it leads to has its own access.
     */
    public static function reasonFor(Route $route): ?string
    {
        $meta = $route->getMeta()[self::META] ?? null;
        if (is_string($meta) && trim($meta) !== '') {
            return $meta;
        }
        if ($route->handler === RedirectHandler::class) {
            return 'A redirect: the route it leads to has its own access';
        }
        foreach (self::targets($route->handler) as $target) {
            $attributes = $target->getAttributes(self::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance()->reason;
            }
        }

        return null;
    }

    /**
     * The method first, then its class.
     *
     * @param \Closure|class-string|array{class-string, string}|string $handler
     * @return list<\ReflectionClass<object>|\ReflectionMethod|\ReflectionFunction>
     */
    private static function targets(\Closure|string|array $handler): array
    {
        if ($handler instanceof \Closure) {
            return [new \ReflectionFunction($handler)];
        }
        [$class, $method] = is_array($handler) ? [$handler[0], $handler[1]] : [$handler, '__invoke'];
        if (!class_exists($class)) {
            return [];
        }
        $reflection = new \ReflectionClass($class);

        return $reflection->hasMethod($method) ? [$reflection->getMethod($method), $reflection] : [$reflection];
    }
}
