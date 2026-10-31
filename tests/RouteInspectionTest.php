<?php

declare(strict_types=1);

namespace Zana\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Core\ApplicationInterface;
use Zana\Core\Inspection\ApplicationCheck;
use Zana\Core\Inspection\Finding;
use Zana\Core\Inspection\InspectionSection;
use Zana\Http\HttpServiceProvider;
use Zana\Routing\Inspection\RouteHandlersCheck;
use Zana\Routing\Inspection\RoutesSection;
use Zana\Routing\Router;
use Zana\Routing\RoutingServiceProvider;

/**
 * ADR-023: routing shows its routes to inspect, and check finds routes that
 * would fail when someone opens them.
 */
final class RouteInspectionTest extends TestCase
{
    public function testRoutesAreShown(): void
    {
        $app = $this->app();
        $section = $this->tagged($app, InspectionSection::TAG, RoutesSection::class);
        assert($section instanceof RoutesSection);

        $routes = $section->inspect($app);

        $this->assertSame('routes', $section->name());
        $this->assertSame(['methods' => 'GET', 'path' => '/registered', 'name' => 'registered', 'handler' => RegisteredPage::class . '::show', 'middleware' => [], 'meta' => ['sitemap' => true]], $routes[0]);
        $this->assertSame('closure', $routes[1]['handler']);
    }

    public function testRoutesThatWouldFailAreFound(): void
    {
        $app = $this->app();
        $check = $this->tagged($app, ApplicationCheck::TAG, RouteHandlersCheck::class);
        assert($check instanceof RouteHandlersCheck);

        $findings = array_map(static fn (Finding $f): array => [$f->id, $f->message], $check->findings($app));

        $this->assertSame([
            ['routing.handler', 'GET /unregistered: its handler ' . UnregisteredPage::class . ' is not registered in the container.'],
            ['routing.class', 'GET /missing: its handler Modules\\Shop\\Presentation\\Gone does not exist.'],
            ['routing.method', 'GET /typo: its handler ' . RegisteredPage::class . '::shwo() does not exist.'],
            ['routing.middleware', 'GET /guarded: its middleware ' . UnregisteredPage::class . ' is not registered in the container.'],
        ], $findings);
        $this->assertStringContainsString('$services->transient(\\' . UnregisteredPage::class . '::class);', (string) $check->findings($app)[0]->hint);
        $this->assertStringEndsWith('RouteInspectionTest.php', str_replace('\\', '/', (string) $check->findings($app)[0]->file));
    }

    private function app(): ApplicationInterface
    {
        $app = Application::configure(sys_get_temp_dir() . '/zana-routing-inspection')
            ->withEnvironment('testing')
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s->transient(RegisteredPage::class))
            ->build();
        $router = $app->container()->get(Router::class);
        assert($router instanceof Router);
        $router->get('/registered', [RegisteredPage::class, 'show'])->name('registered')->meta(['sitemap' => true]);
        $router->get('/closure', static fn (): string => 'ok');
        $router->get('/unregistered', [UnregisteredPage::class, 'show']);
        $router->get('/missing', self::mistake('Modules\\Shop\\Presentation\\Gone'));
        $router->get('/typo', self::mistake([RegisteredPage::class, 'shwo']));
        $router->get('/guarded', [RegisteredPage::class, 'show'])->middleware(self::mistake(UnregisteredPage::class));

        return $app;
    }

    /**
     * A value the route API's types forbid, as a mistake in an application would pass it.
     */
    private static function mistake(mixed $value): mixed
    {
        return $value;
    }

    private function tagged(ApplicationInterface $app, string $tag, string $class): object
    {
        foreach ($app->container()->tagged($tag) as $service) {
            if ($service instanceof $class) {
                return $service;
            }
        }
        $this->fail("{$class} is not tagged {$tag}.");
    }
}

final class RegisteredPage
{
    public function show(): string
    {
        return 'registered';
    }
}

final class UnregisteredPage
{
    public function show(): string
    {
        return 'unregistered';
    }
}
