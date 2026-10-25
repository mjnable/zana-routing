<?php

declare(strict_types=1);

namespace Zana\Routing\Attribute;

use Attribute;

/**
 * Declare a route on a controller method (repeatable).
 *
 * On a class it sets a path prefix and middleware for the class's method
 * routes; on an invokable class without method routes it declares routes to
 * the class itself.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param list<string> $methods
     * @param list<class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware
     * @param array<string, scalar|array<array-key, scalar>> $meta Route::meta()
     */
    public function __construct(
        public readonly string $path = '',
        public readonly array $methods = ['GET'],
        public readonly ?string $name = null,
        public readonly array $middleware = [],
        public readonly array $meta = [],
    ) {
    }
}
