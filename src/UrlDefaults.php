<?php

declare(strict_types=1);

namespace Zana\Routing;

/**
 * Parameters that fill missing ones when URLs are generated: the matched
 * route's defaults and parameters, such as "_locale". RouteDispatcher sets
 * them for the duration of the handler and restores the previous ones after,
 * so this singleton never carries one request's values into the next.
 */
final class UrlDefaults
{
    /** @var list<array<string, scalar>> */
    private array $stack = [];

    /**
     * @param array<string, scalar> $defaults
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(array $defaults, callable $callback): mixed
    {
        $this->stack[] = $defaults;
        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    /**
     * @return array<string, scalar>
     */
    public function all(): array
    {
        return $this->stack === [] ? [] : $this->stack[array_key_last($this->stack)];
    }
}
