<?php

declare(strict_types=1);

namespace Zana\Routing;

use Closure;
use Psr\Http\Server\MiddlewareInterface;
use Zana\Http\Exception\MethodNotAllowedHttpException;
use Zana\Http\Exception\NotFoundHttpException;
use Zana\Routing\Attribute\Route as RouteAttribute;

/**
 * Registers routes and matches requests to them, in registration order.
 */
final class Router
{
    private const ALL_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $named = [];

    /** @var array<string, array<string, Route>> localised name => locale => route */
    private array $localized = [];

    /** @var list<array{string, list<MiddlewareInterface|class-string<MiddlewareInterface>>}> prefix, middleware */
    private array $groups = [];

    /**
     * @param Closure|class-string|array{class-string, string} $handler
     */
    public function get(string $path, Closure|string|array $handler): Route
    {
        return $this->map(['GET'], $path, $handler);
    }

    /**
     * @param Closure|class-string|array{class-string, string} $handler
     */
    public function post(string $path, Closure|string|array $handler): Route
    {
        return $this->map(['POST'], $path, $handler);
    }

    /**
     * @param Closure|class-string|array{class-string, string} $handler
     */
    public function put(string $path, Closure|string|array $handler): Route
    {
        return $this->map(['PUT'], $path, $handler);
    }

    /**
     * @param Closure|class-string|array{class-string, string} $handler
     */
    public function patch(string $path, Closure|string|array $handler): Route
    {
        return $this->map(['PATCH'], $path, $handler);
    }

    /**
     * @param Closure|class-string|array{class-string, string} $handler
     */
    public function delete(string $path, Closure|string|array $handler): Route
    {
        return $this->map(['DELETE'], $path, $handler);
    }

    /**
     * @param Closure|class-string|array{class-string, string} $handler
     */
    public function options(string $path, Closure|string|array $handler): Route
    {
        return $this->map(['OPTIONS'], $path, $handler);
    }

    /**
     * @param Closure|class-string|array{class-string, string} $handler
     */
    public function any(string $path, Closure|string|array $handler): Route
    {
        return $this->map(self::ALL_METHODS, $path, $handler);
    }

    /**
     * @param list<string> $methods
     * @param Closure|class-string|array{class-string, string} $handler
     */
    public function map(array $methods, string $path, Closure|string|array $handler): Route
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groups as [$groupPrefix, $groupMiddleware]) {
            $prefix .= $groupPrefix;
            array_push($middleware, ...$groupMiddleware);
        }

        $route = new Route(
            array_values(array_unique(array_map('strtoupper', $methods))),
            self::normalize($prefix . '/' . ltrim($path, '/')),
            $handler,
            $this->registerName(...),
        );
        $route->middleware(...$middleware);
        $this->routes[] = $route;

        return $route;
    }

    /**
     * One route per locale, each under its locale prefix and carrying the
     * "_locale" default:
     *
     *     $router->localized(['en' => '/about', 'fr' => '/a-propos'], [PageController::class, 'about'])->name('about');
     *     // GET /en/about named "about.en", GET /fr/a-propos named "about.fr"
     *
     * @param array<string, string> $paths locale => path ("/" for the locale's home)
     * @param Closure|class-string|array{class-string, string} $handler
     * @param list<string> $methods
     * @param bool $prefix Prefix each path with "/<locale>"; false when the paths carry it already, or for per-locale domains
     */
    public function localized(array $paths, Closure|string|array $handler, array $methods = ['GET'], bool $prefix = true): LocalizedRoute
    {
        if ($paths === []) {
            throw new RoutingException('A localised route needs at least one locale.');
        }

        $variants = [];
        foreach ($paths as $locale => $path) {
            $path = $prefix ? '/' . $locale . ($path === '/' ? '' : '/' . ltrim($path, '/')) : $path;
            $variants[$locale] = $this->map($methods, $path, $handler)->defaults(['_locale' => $locale]);
        }

        return new LocalizedRoute($variants);
    }

    /**
     * The variants of a localised route name, or null when the name is not localised.
     *
     * @return array<string, Route>|null locale => route
     */
    public function localizedVariants(string $name): ?array
    {
        return $this->localized[$name] ?? null;
    }

    /**
     * Prefix paths and prepend middleware for every route defined in the callback.
     *
     * @param Closure(Router): mixed $routes
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     */
    public function group(string $prefix, Closure $routes, array $middleware = []): void
    {
        $this->groups[] = [rtrim('/' . trim($prefix, '/'), '/'), $middleware];
        try {
            $routes($this);
        } finally {
            array_pop($this->groups);
        }
    }

    /**
     * Register the #[Route] attributes of controller classes.
     *
     * @param class-string ...$controllers
     */
    public function loadAttributes(string ...$controllers): void
    {
        foreach ($controllers as $controller) {
            $class = new \ReflectionClass($controller);
            $classRoutes = array_map(static fn (\ReflectionAttribute $a): RouteAttribute => $a->newInstance(), $class->getAttributes(RouteAttribute::class));

            $methodRoutes = [];
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                    $methodRoutes[] = [$method->getName(), $attribute->newInstance()];
                }
            }

            if ($methodRoutes === []) {
                if (!$class->hasMethod('__invoke')) {
                    throw new RoutingException("{$controller} has no #[Route] methods and is not invokable.");
                }
                foreach ($classRoutes as $attribute) {
                    $this->fromAttribute($attribute, $controller, '', []);
                }
                continue;
            }

            $prefixes = $classRoutes === [] ? [new RouteAttribute()] : $classRoutes;
            foreach ($prefixes as $prefix) {
                foreach ($methodRoutes as [$method, $attribute]) {
                    $this->fromAttribute($attribute, [$controller, $method], $prefix->path, $prefix->middleware);
                }
            }
        }
    }

    /**
     * The first route matching the method and path.
     *
     * @throws NotFoundHttpException When no route has the path
     * @throws MethodNotAllowedHttpException When routes have the path but not the method
     */
    public function match(string $method, string $path): RouteMatch
    {
        $method = strtoupper($method);
        $path = self::normalize($path);
        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $route->matchPath($path);
            if ($parameters === null) {
                continue;
            }

            if ($route->allows($method)) {
                return new RouteMatch($route, $parameters);
            }

            array_push($allowed, ...$route->methods);
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException(array_values(array_unique($allowed)));
        }

        throw new NotFoundHttpException("No route matches {$method} {$path}.");
    }

    /**
     * @throws RoutingException For an unknown name
     */
    public function named(string $name): Route
    {
        return $this->named[$name] ?? throw new RoutingException("No route is named {$name}.");
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Routes as plain data for caching. Closure handlers cannot be exported.
     *
     * @return list<array{methods: list<string>, path: string, handler: string|array{string, string}, name: ?string, middleware: list<string>, patterns: array<string, string>, defaults: array<string, scalar>, meta: array<string, scalar|array<array-key, scalar>>}>
     */
    public function export(): array
    {
        return array_map(static function (Route $route): array {
            if ($route->handler instanceof Closure) {
                throw new RoutingException("Route {$route->path} uses a closure and cannot be exported.");
            }

            $middleware = [];
            foreach ($route->getMiddleware() as $item) {
                if (!is_string($item)) {
                    throw new RoutingException("Route {$route->path} uses a middleware instance and cannot be exported.");
                }
                $middleware[] = $item;
            }

            return [
                'methods' => $route->methods,
                'path' => $route->path,
                'handler' => $route->handler,
                'name' => $route->getName(),
                'middleware' => $middleware,
                'patterns' => $route->patterns(),
                'defaults' => $route->getDefaults(),
                'meta' => $route->getMeta(),
            ];
        }, $this->routes);
    }

    /**
     * @param list<array{methods: list<string>, path: string, handler: string|array{string, string}, name: ?string, middleware: list<string>, patterns: array<string, string>, defaults?: array<string, scalar>, meta?: array<string, scalar|array<array-key, scalar>>}> $routes
     */
    public static function fromExport(array $routes): self
    {
        $router = new self();
        foreach ($routes as $data) {
            /** @var class-string|array{class-string, string} $handler */
            $handler = $data['handler'];
            $route = $router->map($data['methods'], $data['path'], $handler);
            foreach ($data['patterns'] as $parameter => $regex) {
                $route->where($parameter, $regex);
            }
            $route->defaults($data['defaults'] ?? []);
            $route->meta($data['meta'] ?? []);
            /** @var list<class-string<MiddlewareInterface>> $middleware */
            $middleware = $data['middleware'];
            $route->middleware(...$middleware);
            if ($data['name'] !== null) {
                $route->name($data['name']);
            }
        }

        return $router;
    }

    public static function normalize(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function registerName(string $name, Route $route): void
    {
        if (isset($this->named[$name])) {
            throw new RoutingException("The route name {$name} is already used by {$this->named[$name]->path}.");
        }

        $this->named[$name] = $route;

        $locale = $route->getDefaults()['_locale'] ?? null;
        if (is_string($locale) && str_ends_with($name, ".{$locale}")) {
            $this->localized[substr($name, 0, -strlen($locale) - 1)][$locale] = $route;
        }
    }

    /**
     * @param class-string|array{class-string, string} $handler
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    private function fromAttribute(RouteAttribute $attribute, string|array $handler, string $prefix, array $middleware): void
    {
        $route = $this->map($attribute->methods, rtrim($prefix, '/') . '/' . ltrim($attribute->path, '/'), $handler);
        $route->middleware(...$middleware, ...$attribute->middleware);
        $route->meta($attribute->meta);
        if ($attribute->name !== null) {
            $route->name($attribute->name);
        }
    }
}
