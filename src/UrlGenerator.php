<?php

declare(strict_types=1);

namespace Zana\Routing;

/**
 * Builds paths for named routes.
 */
final readonly class UrlGenerator
{
    public function __construct(private Router $router)
    {
    }

    /**
     * Parameters not in the path are added to the query string, before $query.
     *
     * @param array<string, scalar|\Stringable> $parameters
     * @param array<string, mixed> $query
     * @throws RoutingException For an unknown route, or a missing or invalid parameter
     */
    public function generate(string $name, array $parameters = [], array $query = []): string
    {
        $route = $this->router->named($name);

        $values = [];
        foreach ($route->patterns() as $parameter => $regex) {
            if (!array_key_exists($parameter, $parameters)) {
                throw new RoutingException("Route {$name} needs the parameter {$parameter}.");
            }

            $value = $parameters[$parameter];
            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if (preg_match('#^' . $regex . '$#u', $value) !== 1) {
                throw new RoutingException("Route {$name}: \"{$value}\" does not match {$parameter} ({$regex}).");
            }

            $values[$parameter] = $value;
            unset($parameters[$parameter]);
        }

        $path = $route->buildPath($values);
        $extra = [...array_map(static fn (mixed $v): mixed => $v instanceof \Stringable ? (string) $v : $v, $parameters), ...$query];

        return $extra === [] ? $path : $path . '?' . http_build_query($extra, '', '&', PHP_QUERY_RFC3986);
    }
}
