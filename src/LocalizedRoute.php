<?php

declare(strict_types=1);

namespace Zana\Routing;

use Psr\Http\Server\MiddlewareInterface;

/**
 * The variants of a localised route, one per locale (Router::localized()).
 * Naming it "about" names the variants "about.en", "about.fr", ...; the
 * URL generator then picks the variant for the current or requested locale.
 */
final readonly class LocalizedRoute
{
    /**
     * @param array<string, Route> $variants locale => route
     */
    public function __construct(public array $variants)
    {
    }

    public function name(string $name): self
    {
        foreach ($this->variants as $locale => $route) {
            $route->name("{$name}.{$locale}");
        }

        return $this;
    }

    /**
     * @param MiddlewareInterface|class-string<MiddlewareInterface> ...$middleware
     */
    public function middleware(MiddlewareInterface|string ...$middleware): self
    {
        foreach ($this->variants as $route) {
            $route->middleware(...$middleware);
        }

        return $this;
    }

    public function where(string $parameter, string $regex): self
    {
        foreach ($this->variants as $route) {
            $route->where($parameter, $regex);
        }

        return $this;
    }

    /**
     * @param array<string, scalar> $defaults
     */
    public function defaults(array $defaults): self
    {
        foreach ($this->variants as $route) {
            $route->defaults($defaults);
        }

        return $this;
    }
}
