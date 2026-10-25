<?php

declare(strict_types=1);

namespace Zana\Routing;

use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Container\ServiceLifetime;
use Zana\Core\ServiceProvider;
use Zana\Http\HttpKernel;

/**
 * Registers the router and URL generator, and makes RouteDispatcher the
 * terminal HTTP handler. Modules add routes in boot().
 */
final class RoutingServiceProvider extends ServiceProvider
{
    public function register(ServiceCollectionInterface $services): void
    {
        $services
            ->singleton(Router::class)
            ->singleton(UrlDefaults::class)
            ->singleton(UrlGenerator::class)
            ->scoped(RouteDispatcher::class);

        $handler = static fn (ServiceResolverInterface $scope): RouteDispatcher => $scope->get(RouteDispatcher::class);

        if ($services->has(HttpKernel::HANDLER)) {
            $services->replace(HttpKernel::HANDLER, $handler, lifetime: ServiceLifetime::Scoped);
        } else {
            $services->scoped(HttpKernel::HANDLER, $handler);
        }
    }

    public function origin(): string
    {
        return 'package:mj-zana/routing';
    }
}
