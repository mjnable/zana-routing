<?php

declare(strict_types=1);

namespace Zana\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Http\Exception\MethodNotAllowedHttpException;
use Zana\Http\Exception\NotFoundHttpException;
use Zana\Routing\Router;
use Zana\Routing\RoutingException;
use Zana\Routing\UrlGenerator;

final class RouterTest extends TestCase
{
    public function testMethodsAndFirstMatchWins(): void
    {
        $router = new Router();
        $first = $router->get('/patients/{id}', HealthAction::class);
        $router->get('/patients/{id}', HealthAction::class);
        $post = $router->post('/patients', HealthAction::class);
        $any = $router->any('/ping', HealthAction::class);
        $mapped = $router->map(['put', 'patch'], '/patients/{id}', HealthAction::class);

        $this->assertSame($first, $router->match('GET', '/patients/7')->route);
        $this->assertSame($post, $router->match('post', '/patients')->route);
        $this->assertSame($any, $router->match('OPTIONS', '/ping')->route);
        $this->assertSame(['PUT', 'PATCH'], $mapped->methods);
        $this->assertSame($first, $router->match('HEAD', '/patients/7')->route, 'HEAD matches GET routes');
    }

    public function testParametersAndPatterns(): void
    {
        $router = new Router();
        $router->get('/years/{year:\d{4}}/months/{month}', HealthAction::class);
        $router->get('/files/{path:.+}', HealthAction::class);
        $router->get('/users/{id}', HealthAction::class)->where('id', '[a-z]+');

        $this->assertSame(['year' => '2026', 'month' => 'oct'], $router->match('GET', '/years/2026/months/oct')->parameters);
        $this->assertSame(['path' => 'a/b c.txt'], $router->match('GET', '/files/a/b%20c.txt')->parameters, 'values are URL-decoded');
        $this->assertSame(['id' => 'ada'], $router->match('GET', '/users/ada')->parameters);

        $this->expectException(NotFoundHttpException::class);
        $router->match('GET', '/users/42');
    }

    public function testTrailingSlashesAreIgnored(): void
    {
        $router = new Router();
        $router->get('/patients/', PatientController::class);
        $root = $router->get('/', HealthAction::class);

        $this->assertSame('/patients', $router->routes()[0]->path);
        $this->assertSame(PatientController::class, $router->match('GET', '/patients/')->route->handler);
        $this->assertSame($root, $router->match('GET', '')->route);
    }

    public function testGroupsNestPrefixesAndMiddleware(): void
    {
        $router = new Router();
        $router->group('/api/', static function (Router $r): void {
            $r->group('v1', static function (Router $r): void {
                $r->get('/bmi', HealthAction::class)->middleware(TagMiddleware::class);
            }, [GroupMiddleware::class]);
        }, [TagMiddleware::class]);
        $router->get('/outside', HealthAction::class);

        $route = $router->match('GET', '/api/v1/bmi')->route;
        $this->assertSame([TagMiddleware::class, GroupMiddleware::class, TagMiddleware::class], $route->getMiddleware());
        $this->assertSame([], $router->match('GET', '/outside')->route->getMiddleware());
    }

    public function testMethodNotAllowedListsTheAllowedMethods(): void
    {
        $router = new Router();
        $router->get('/patients/{id}', HealthAction::class);
        $router->delete('/patients/{id}', HealthAction::class);

        try {
            $router->match('PUT', '/patients/7');
            $this->fail('Expected a 405.');
        } catch (MethodNotAllowedHttpException $e) {
            $this->assertSame(['GET', 'DELETE'], $e->allowed);
        }
    }

    public function testInvalidDefinitionsAreRejected(): void
    {
        $router = new Router();
        $router->get('/a', HealthAction::class)->name('dup');

        foreach ([
            [static fn () => $router->get('/b', HealthAction::class)->name('dup'), 'already used'],
            [static fn () => $router->get('/{id}/{id}', HealthAction::class), 'uses the parameter id twice'],
            [static fn () => $router->get('/{1bad}', HealthAction::class), 'invalid parameter name'],
            [static fn () => $router->get('/{id:[}', HealthAction::class), 'invalid parameter pattern'],
            [static fn () => $router->get('/{id', HealthAction::class), 'unclosed parameter brace'],
            [static fn () => $router->get('/c', HealthAction::class)->where('missing', '\d+'), 'has no parameter missing'],
            [static fn () => $router->get('/d', HealthAction::class)->name('d')->name('e'), 'already named'],
            [static fn () => $router->named('nope'), 'No route is named nope'],
        ] as [$call, $message]) {
            try {
                $call();
                $this->fail("Expected: {$message}");
            } catch (RoutingException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testUrlGeneration(): void
    {
        $router = new Router();
        $router->get('/patients/{id:\d+}/files/{name}', HealthAction::class)->name('file');
        $router->get('/', HealthAction::class)->name('home');
        $urls = new UrlGenerator($router);

        $this->assertSame('/patients/7/files/scan%201.png?tab=history&page=2', $urls->generate('file', ['id' => 7, 'name' => 'scan 1.png', 'tab' => 'history'], ['page' => 2]));
        $this->assertSame('/', $urls->generate('home'));

        foreach ([
            [static fn () => $urls->generate('file', ['id' => 7]), 'needs the parameter name'],
            [static fn () => $urls->generate('file', ['id' => 'x', 'name' => 'n']), 'does not match id'],
        ] as [$call, $message]) {
            try {
                $call();
                $this->fail("Expected: {$message}");
            } catch (RoutingException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testAttributeRoutes(): void
    {
        $router = new Router();
        $router->loadAttributes(PatientController::class, HealthAction::class);

        $show = $router->match('GET', '/patients/7')->route;
        $this->assertSame([PatientController::class, 'show'], $show->handler);
        $this->assertSame('patients.show', $show->getName());
        $this->assertSame([TagMiddleware::class], $show->getMiddleware());
        $this->assertSame([PatientController::class, 'index'], $router->named('patients.index')->handler);
        $this->assertSame([PatientController::class, 'remove'], $router->match('DELETE', '/patients/7')->route->handler);
        $this->assertSame([PatientController::class, 'remove'], $router->match('POST', '/patients/7/archive')->route->handler);
        $this->assertSame(HealthAction::class, $router->named('health')->handler);

        $this->expectException(RoutingException::class);
        $router->loadAttributes(NotRoutable::class);
    }

    public function testExportAndImport(): void
    {
        $router = new Router();
        $router->loadAttributes(PatientController::class);
        $router->get('/users/{id}', HealthAction::class)->where('id', '[a-z]+')->name('users');

        $restored = Router::fromExport($router->export());

        $this->assertSame($router->export(), $restored->export());
        $this->assertSame(['id' => 'ada'], $restored->match('GET', '/users/ada')->parameters);
        $this->assertSame('/users/ada', (new UrlGenerator($restored))->generate('users', ['id' => 'ada']));
    }

    public function testClosuresCannotBeExported(): void
    {
        $router = new Router();
        $router->get('/', static fn (): string => 'x');

        $this->expectException(RoutingException::class);
        $router->export();
    }
}
