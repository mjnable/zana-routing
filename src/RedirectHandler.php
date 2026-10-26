<?php

declare(strict_types=1);

namespace Zana\Routing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zana\Http\BasePath;
use Zana\Http\ResponseFactory;

/**
 * Serves the routes that Router::redirect() registers (FE-14). The target
 * lives in the route's metadata, so redirect routes stay exportable:
 *
 * - a route name: generated with the matched parameters that route uses,
 *   and the fixed parameters given to redirect();
 * - a path ("/fr/a-propos"): prefixed with the application's base path;
 * - a URL ("https://..."): used as is.
 *
 * "{name}" in a path or URL is replaced by the matched parameter, encoded.
 * The target is always fixed in code: nothing in the request chooses it.
 */
final readonly class RedirectHandler
{
    public const string META = '_redirect';
    public const string META_HEADERS = '_redirect_headers';
    public const string META_PARAMETERS = '_redirect_parameters';

    public function __construct(
        private Router $router,
        private UrlGenerator $urls,
        private ResponseFactory $responses,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $route = $request->getAttribute('_route');
        if (!$route instanceof Route) {
            throw new RoutingException('RedirectHandler serves redirect routes only.');
        }
        $meta = $route->getMeta();
        $redirect = $meta[self::META] ?? null;
        if (!is_array($redirect) || !is_string($redirect['to'] ?? null) || !is_int($redirect['status'] ?? null)) {
            throw new RoutingException("Route {$route->path} is not a redirect route.");
        }

        $matched = [];
        foreach (array_keys($route->patterns()) as $parameter) {
            $value = $request->getAttribute($parameter);
            if (is_scalar($value)) {
                $matched[$parameter] = (string) $value;
            }
        }

        $response = $this->responses->redirect($this->target($redirect['to'], $matched, $meta[self::META_PARAMETERS] ?? [], $request), $redirect['status']);
        $headers = $meta[self::META_HEADERS] ?? [];
        foreach (is_array($headers) ? $headers : [] as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }

        return $response;
    }

    /**
     * @param array<string, string> $matched
     * @param scalar|array<array-key, scalar> $fixed
     */
    private function target(string $to, array $matched, mixed $fixed, ServerRequestInterface $request): string
    {
        if (Router::isRedirectUrl($to)) {
            $url = self::substitute($to, $matched);
            if (str_starts_with($url, '/')) {
                $base = $request->getAttribute(BasePath::ATTRIBUTE);

                return (is_string($base) ? $base : '') . $url;
            }

            return $url;
        }

        $routes = $this->router->localizedVariants($to) ?? [$this->router->named($to)];
        $uses = [];
        foreach ($routes as $target) {
            $uses += $target->patterns();
        }

        return $this->urls->generate($to, [...array_intersect_key($matched, $uses), ...(is_array($fixed) ? $fixed : [])]);
    }

    /**
     * @param array<string, string> $matched
     */
    private static function substitute(string $url, array $matched): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', static function (array $m) use ($matched): string {
            return array_key_exists($m[1], $matched)
                ? rawurlencode($matched[$m[1]])
                : throw new RoutingException("The redirect target {$m[0]} has no matching route parameter.");
        }, $url);
    }
}
