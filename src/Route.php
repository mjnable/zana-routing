<?php

declare(strict_types=1);

namespace Zana\Routing;

use Closure;
use Psr\Http\Server\MiddlewareInterface;

/**
 * One route: methods, a path pattern, a handler, and optional name, middleware and constraints.
 *
 * Path parameters are written {name} (any segment) or {name:regex}.
 */
final class Route
{
    private const NAME = '[a-zA-Z_][a-zA-Z0-9_]*';

    private const DEFAULT_PATTERN = '[^/]+';

    private ?string $name = null;

    /** @var list<MiddlewareInterface|class-string<MiddlewareInterface>> */
    private array $middleware = [];

    /** @var array<string, string> parameter => regex */
    private array $patterns = [];

    /** @var list<string|array{string}> Literal text, or [parameter name] */
    private readonly array $parts;

    private ?string $regex = null;

    /**
     * @param list<string> $methods Upper-case HTTP methods
     * @param Closure|class-string|array{class-string, string} $handler
     * @param (Closure(string, Route): void)|null $onName Registers the name with the router
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly Closure|string|array $handler,
        private readonly ?Closure $onName = null,
    ) {
        [$this->parts, $this->patterns] = self::parse($path);
    }

    public function name(string $name): self
    {
        if ($this->name !== null) {
            throw new RoutingException("Route {$this->path} is already named {$this->name}.");
        }

        $this->onName?->__invoke($name, $this);
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param MiddlewareInterface|class-string<MiddlewareInterface> ...$middleware
     */
    public function middleware(MiddlewareInterface|string ...$middleware): self
    {
        array_push($this->middleware, ...array_values($middleware));

        return $this;
    }

    /**
     * @return list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Constrain a parameter with a regular expression (without delimiters or anchors).
     */
    public function where(string $parameter, string $regex): self
    {
        if (!array_key_exists($parameter, $this->patterns)) {
            throw new RoutingException("Route {$this->path} has no parameter {$parameter}.");
        }

        self::assertValidRegex($regex, $this->path);
        $this->patterns[$parameter] = $regex;
        $this->regex = null;

        return $this;
    }

    /**
     * @return array<string, string> parameter => regex
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    public function allows(string $method): bool
    {
        return in_array($method, $this->methods, true)
            || ($method === 'HEAD' && in_array('GET', $this->methods, true));
    }

    /**
     * The parameters if the path matches, URL-decoded, otherwise null.
     *
     * @return array<string, string>|null
     */
    public function matchPath(string $path): ?array
    {
        if (preg_match($this->regex(), $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];
        foreach (array_keys($this->patterns) as $name) {
            $parameters[$name] = rawurldecode($matches[$name]);
        }

        return $parameters;
    }

    /**
     * Build the path with the given parameter values (already validated by the caller).
     *
     * @param array<string, string> $values
     */
    public function buildPath(array $values): string
    {
        $path = '';
        foreach ($this->parts as $part) {
            $path .= is_array($part) ? rawurlencode($values[$part[0]]) : $part;
        }

        return $path;
    }

    private function regex(): string
    {
        if ($this->regex !== null) {
            return $this->regex;
        }

        $regex = '';
        foreach ($this->parts as $part) {
            $regex .= is_array($part)
                ? '(?P<' . $part[0] . '>' . $this->patterns[$part[0]] . ')'
                : preg_quote($part, '#');
        }

        return $this->regex = '#^' . $regex . '$#u';
    }

    /**
     * @return array{list<string|array{string}>, array<string, string>}
     */
    private static function parse(string $path): array
    {
        $parts = [];
        $patterns = [];
        $literal = '';
        $length = strlen($path);

        for ($i = 0; $i < $length; $i++) {
            if ($path[$i] !== '{') {
                $literal .= $path[$i];
                continue;
            }

            $depth = 1;
            $start = $i + 1;
            while (++$i < $length && $depth > 0) {
                $depth += match ($path[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };
            }
            if ($depth !== 0) {
                throw new RoutingException("Route {$path} has an unclosed parameter brace.");
            }
            $i--;

            $definition = substr($path, $start, $i - $start);
            [$name, $regex] = array_pad(explode(':', $definition, 2), 2, null);

            if (preg_match('/^' . self::NAME . '$/', (string) $name) !== 1) {
                throw new RoutingException("Route {$path} has an invalid parameter name: {$name}.");
            }
            if (array_key_exists((string) $name, $patterns)) {
                throw new RoutingException("Route {$path} uses the parameter {$name} twice.");
            }

            $regex ??= self::DEFAULT_PATTERN;
            self::assertValidRegex($regex, $path);

            if ($literal !== '') {
                $parts[] = $literal;
                $literal = '';
            }
            $parts[] = [(string) $name];
            $patterns[(string) $name] = $regex;
        }

        if ($literal !== '') {
            $parts[] = $literal;
        }

        return [$parts, $patterns];
    }

    private static function assertValidRegex(string $regex, string $path): void
    {
        if (@preg_match('#^' . $regex . '$#u', '') === false) {
            throw new RoutingException("Route {$path} has an invalid parameter pattern: {$regex}.");
        }
    }
}
