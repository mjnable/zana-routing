<?php

declare(strict_types=1);

namespace Zana\Routing;

/**
 * Builds paths for named routes.
 *
 * Missing parameters are filled from the URL defaults of the current request
 * (UrlDefaults), so a page can link to itself or its siblings without
 * repeating them. For a localised name ("about", registered with
 * Router::localized()), the variant is picked from the "_locale" parameter,
 * then the current request's locale, then the first locale registered.
 *
 * Paths start with the application's base path (http.base_path) when it
 * runs from a sub-folder.
 */
final readonly class UrlGenerator
{
    public function __construct(
        private Router $router,
        private ?UrlDefaults $defaults = null,
        private string $basePath = '',
    ) {
    }

    /**
     * Parameters not in the path are added to the query string, before $query.
     *
     * @param array<string, scalar|\Stringable> $parameters
     * @param array<string, mixed> $query
     * @throws RoutingException For an unknown route, an unknown locale, or a missing or invalid parameter
     */
    public function generate(string $name, array $parameters = [], array $query = []): string
    {
        $defaults = $this->defaults?->all() ?? [];
        $variants = $this->router->localizedVariants($name);

        if ($variants !== null) {
            $locale = (string) ($parameters['_locale'] ?? $defaults['_locale'] ?? array_key_first($variants));
            unset($parameters['_locale']);
            $route = $variants[$locale] ?? throw new RoutingException("Route {$name} has no variant for the locale {$locale}.");
        } else {
            $route = $this->router->named($name);
        }

        $values = [];
        foreach ($route->patterns() as $parameter => $regex) {
            if (array_key_exists($parameter, $parameters)) {
                $value = $parameters[$parameter];
                unset($parameters[$parameter]);
            } elseif (array_key_exists($parameter, $defaults)) {
                $value = $defaults[$parameter];
            } else {
                throw new RoutingException("Route {$name} needs the parameter {$parameter}.");
            }

            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            if (preg_match('#^' . $regex . '$#u', $value) !== 1) {
                throw new RoutingException("Route {$name}: \"{$value}\" does not match {$parameter} ({$regex}).");
            }

            $values[$parameter] = $value;
        }

        $path = $this->basePath . $route->buildPath($values);
        $extra = [...array_map(static fn (mixed $v): mixed => $v instanceof \Stringable ? (string) $v : $v, $parameters), ...$query];

        return $extra === [] ? $path : $path . '?' . http_build_query($extra, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The path of a localised route in every locale, for hreflang links and
     * language switchers.
     *
     * @param array<string, scalar|\Stringable> $parameters
     * @return array<string, string> locale => path
     * @throws RoutingException When the name is not localised
     */
    public function alternates(string $name, array $parameters = []): array
    {
        $variants = $this->router->localizedVariants($name)
            ?? throw new RoutingException("Route {$name} is not localised.");

        $paths = [];
        foreach (array_keys($variants) as $locale) {
            $paths[$locale] = $this->generate($name, [...$parameters, '_locale' => $locale]);
        }

        return $paths;
    }
}
