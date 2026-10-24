<?php

declare(strict_types=1);

namespace Zana\Routing;

/**
 * A matched route with its URL-decoded parameters.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public Route $route,
        public array $parameters,
    ) {
    }
}
