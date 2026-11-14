<?php

declare(strict_types=1);

namespace Zana\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Routing\Attribute\PublicRoute;
use Zana\Routing\Router;
use Zana\Routing\RoutingException;

/**
 * 2.21, FE-68: a route says anyone may reach it, and why: on its handler's
 * method, its class, a closure, or its group. Anything else says nothing.
 */
final class PublicRouteTest extends TestCase
{
    public function testTheReasonComesFromTheMethodTheClassAClosureOrTheGroup(): void
    {
        $router = new Router();
        $router->get('/about', [PublicPages::class, 'about']);
        $router->get('/contact', [PublicPages::class, 'contact']);
        $router->get('/admin', [PrivatePages::class, 'index']);
        $router->get('/ping', #[PublicRoute(reason: 'Uptime checks')] static fn (): string => 'pong');
        $router->get('/closure', static fn (): string => 'x');
        $router->group('/site', static function (Router $r): void {
            $r->get('/home', [PrivatePages::class, 'index']);
            $r->group('/inner', static fn (Router $r) => $r->get('/x', [PrivatePages::class, 'index']), public: 'The inner part');
        }, public: 'The public website');
        $router->get('/after', [PrivatePages::class, 'index']);
        $router->redirect('/old', '/about');

        $reasons = [];
        foreach ($router->routes() as $route) {
            $reasons[$route->path] = PublicRoute::reasonFor($route);
        }

        $this->assertSame([
            '/about' => 'The about page, for everyone',   // the method wins over its class
            '/contact' => 'The pages of the public website',
            '/admin' => null,
            '/ping' => 'Uptime checks',
            '/closure' => null,
            '/site/home' => 'The public website',
            '/site/inner/x' => 'The inner part',
            '/after' => null,                             // the group ended
            '/old' => 'A redirect: the route it leads to has its own access',
        ], $reasons);
    }

    public function testAGroupsReasonSurvivesTheRouteCache(): void
    {
        $router = new Router();
        $router->group('', static fn (Router $r) => $r->get('/home', [PrivatePages::class, 'index']), public: 'The public website');

        $cached = Router::fromExport($router->export());

        $this->assertSame('The public website', PublicRoute::reasonFor($cached->routes()[0]));
    }

    public function testAReasonIsRequired(): void
    {
        foreach ([
            static fn () => (new Router())->group('', static fn () => null, public: '  '),
            static fn () => new PublicRoute(''),
            static fn () => PublicRoute::reasonFor(((new Router())->get('/x', [EmptyReason::class, 'index']))),
        ] as $i => $missing) {
            try {
                $missing();
                $this->fail("case {$i}: an empty reason was accepted");
            } catch (RoutingException $e) {
                $this->assertStringContainsString('reason', $e->getMessage());
            }
        }
    }
}

#[PublicRoute(reason: 'The pages of the public website')]
final class PublicPages
{
    #[PublicRoute(reason: 'The about page, for everyone')]
    public function about(): string
    {
        return 'about';
    }

    public function contact(): string
    {
        return 'contact';
    }
}

final class PrivatePages
{
    public function index(): string
    {
        return 'index';
    }
}

final class EmptyReason
{
    #[PublicRoute(reason: '')]
    public function index(): string
    {
        return '';
    }
}
