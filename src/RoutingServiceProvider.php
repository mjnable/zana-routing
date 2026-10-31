<?php

declare(strict_types=1);

namespace Zana\Routing;

use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Container\ServiceLifetime;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\InspectionSection;
use Zana\Core\ServiceProvider;
use Zana\Routing\Inspection\RouteHandlersCheck;
use Zana\Routing\Inspection\RoutesSection;
use Zana\Configuration\ConfigurationInterface;
use Zana\Http\BasePath;
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
            ->singleton(UrlGenerator::class, static function (ServiceResolverInterface $r): UrlGenerator {
                $router = $r->get(Router::class);
                $defaults = $r->get(UrlDefaults::class);
                assert($router instanceof Router && $defaults instanceof UrlDefaults);

                return new UrlGenerator($router, $defaults, BasePath::fromConfig($r->get(ConfigurationInterface::class)));
            })->uses(Router::class, UrlDefaults::class, ConfigurationInterface::class)
            ->scoped(RouteDispatcher::class)
            ->scoped(RedirectHandler::class)
            ->singleton(RoutesSection::class)
            ->tag(InspectionSection::TAG, RoutesSection::class)
            ->singleton(RouteHandlersCheck::class)
            ->tag(ApplicationCheck::TAG, RouteHandlersCheck::class);

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
