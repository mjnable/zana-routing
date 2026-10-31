<?php

declare(strict_types=1);

namespace Zana\Routing\Inspection;

use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\InspectionSection;
use Zana\Routing\Route;
use Zana\Routing\Router;

/**
 * The "routes" section of the inspect command (ADR-023): methods, path,
 * name, handler, middleware and metadata of every route, in matching order.
 */
final readonly class RoutesSection implements InspectionSection
{
    public function __construct(private Router $router)
    {
    }

    public function name(): string
    {
        return 'routes';
    }

    public function inspect(ApplicationInterface $application): array
    {
        return array_map(static fn (Route $route): array => [
            'methods' => implode('|', $route->methods),
            'path' => $route->path,
            'name' => $route->getName(),
            'handler' => self::handler($route->handler),
            'middleware' => array_map(static fn (mixed $middleware): string => is_string($middleware) ? $middleware : get_debug_type($middleware), $route->getMiddleware()),
            'meta' => $route->getMeta(),
        ], $this->router->routes());
    }

    /**
     * @param \Closure|string|array{0: string, 1: string} $handler
     */
    public static function handler(\Closure|string|array $handler): string
    {
        return match (true) {
            $handler instanceof \Closure => 'closure',
            is_array($handler) => $handler[0] . '::' . $handler[1],
            default => $handler,
        };
    }
}
