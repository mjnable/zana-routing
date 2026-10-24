<?php

declare(strict_types=1);

namespace Zana\Routing\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Routing\Route;
use Zana\Routing\Router;
use Zana\Routing\RoutingServiceProvider;

final class DispatchTest extends TestCase
{
    private HttpKernel $kernel;

    private Router $router;

    protected function setUp(): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-routing-app')
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->singleton(PatientDirectory::class)
                ->transient(PatientController::class)
                ->transient(HealthAction::class)
                ->transient(TagMiddleware::class))
            ->build()
            ->container();

        $this->router = $container->get(Router::class);
        $this->router->loadAttributes(PatientController::class, HealthAction::class);
        $this->kernel = $container->get(HttpKernel::class);
    }

    public function testControllersGetDependenciesAndConvertedParameters(): void
    {
        $response = $this->send('GET', '/patients/7');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['id' => 7, 'name' => 'Ada', 'active' => true], $this->json($response));
        $this->assertSame('tagged', $response->getHeaderLine('X-Tags'), 'route middleware ran');
    }

    public function testInvokableHandlersReceiveTheRequest(): void
    {
        $this->assertSame('ok GET', (string) $this->send('GET', '/health')->getBody());
    }

    public function testReturnValuesBecomeResponses(): void
    {
        $this->router->get('/null', static fn (): null => null);
        $this->router->get('/html', static fn (): string => '<p>hi</p>');
        $this->router->get('/serializable', static fn (): Measurement => new Measurement(22.5));
        $this->router->get('/object', static fn (): PlainResult => new PlainResult());
        $this->router->get('/number', static fn (): int => 42);

        $this->assertSame(204, $this->send('GET', '/null')->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $this->send('GET', '/html')->getHeaderLine('Content-Type'));
        $this->assertSame(['bmi' => 22.5, 'category' => 'normal'], $this->json($this->send('GET', '/serializable')));
        $this->assertSame(['status' => 'saved'], $this->json($this->send('GET', '/object')));
        $this->assertSame('42', (string) $this->send('GET', '/number')->getBody());
    }

    public function testRequestAttributesAndBoolConversion(): void
    {
        $this->router->get('/flags/{enabled}', static function (bool $enabled, ServerRequestInterface $request): array {
            $route = $request->getAttribute('_route');

            return ['enabled' => $enabled, 'path' => $route instanceof Route ? $route->path : null, 'raw' => $request->getAttribute('enabled')];
        });

        $this->assertSame(['enabled' => false, 'path' => '/flags/{enabled}', 'raw' => 'false'], $this->json($this->send('GET', '/flags/false')));
        $this->assertSame(404, $this->send('GET', '/flags/maybe')->getStatusCode(), 'an unconvertible value is a 404');
    }

    public function testRoutingErrorsBecomeResponses(): void
    {
        $this->assertSame(404, $this->send('GET', '/nowhere')->getStatusCode());

        $response = $this->send('PUT', '/patients/7');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, DELETE', $response->getHeaderLine('Allow'));
    }

    public function testControllersMustBeRegistered(): void
    {
        $this->router->get('/unregistered', [NotRoutable::class, '__invoke']);

        $this->assertSame(500, $this->send('GET', '/unregistered')->getStatusCode());
    }

    public function testRoutingWorksWithoutTheHttpProviderRegisteredFirst(): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-routing-app')
            ->withProviders(RoutingServiceProvider::class, HttpServiceProvider::class)
            ->build()
            ->container();

        $container->get(Router::class)->get('/', static fn (): string => 'home');

        $this->assertSame('home', (string) $container->get(HttpKernel::class)->handle(new ServerRequest('GET', '/'))->getBody());
    }

    private function send(string $method, string $path): ResponseInterface
    {
        return $this->kernel->handle(new ServerRequest($method, $path, ['Accept' => 'application/json']));
    }

    /**
     * @return array<mixed>
     */
    private function json(ResponseInterface $response): array
    {
        /** @var array<mixed> */
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
