<?php

declare(strict_types=1);

namespace Zana\Routing\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Zana\Core\Application;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Routing\Router;
use Zana\Routing\RoutingException;
use Zana\Routing\RoutingServiceProvider;

final class RedirectRoutesTest extends TestCase
{
    private HttpKernel $kernel;

    private Router $router;

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = []): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-routing-redirects')
            ->withEnvironment('testing')
            ->withConfiguration($config)
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class)
            ->build()
            ->container();
        $router = $container->get(Router::class);
        $kernel = $container->get(HttpKernel::class);
        assert($router instanceof Router && $kernel instanceof HttpKernel);
        [$this->router, $this->kernel] = [$router, $kernel];

        $this->router->localized(['en' => '/about', 'fr' => '/a-propos'], [MetaPage::class, 'about'])->name('about');
        $this->router->get('/blog/{slug}', [MetaPage::class, 'about'])->name('blog.show');
    }

    public function testOldUrlsRedirectPermanentlyToRoutes(): void
    {
        $this->boot();
        $this->router->redirect('/about-us', 'about', parameters: ['_locale' => 'fr']);
        $this->router->redirect('/news/{slug}/{year}', 'blog.show');

        $about = $this->get('/about-us');
        $this->assertSame(301, $about->getStatusCode());
        $this->assertSame('/fr/a-propos', $about->getHeaderLine('Location'));

        $this->assertSame('/blog/launch', $this->get('/news/launch/2019')->getHeaderLine('Location'), 'parameters the target uses carry over; others are dropped');
        $this->assertSame(301, $this->kernel->handle(new ServerRequest('HEAD', '/about-us'))->getStatusCode());
        $this->assertSame(405, $this->kernel->handle(new ServerRequest('POST', '/about-us'))->getStatusCode());
    }

    public function testExternalTargetsWithHeaders(): void
    {
        $this->boot();
        $this->router->redirect('/webmail', 'https://mail.example.com:2096/', 302, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
        ]);

        $response = $this->get('/webmail');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://mail.example.com:2096/', $response->getHeaderLine('Location'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        $this->assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testPathTargetsTakeParametersAndTheBasePath(): void
    {
        $this->boot(['http.base_path' => '/site']);
        $this->router->redirect('/old/{name}', '/docs/{name}.html', 308);

        $response = $this->get('/site/old/a b');
        $this->assertSame(308, $response->getStatusCode());
        $this->assertSame('/site/docs/a%20b.html', $response->getHeaderLine('Location'));
    }

    public function testRedirectRoutesSurviveTheRouteCache(): void
    {
        $router = new Router();
        $router->redirect('/webmail', 'https://mail.example.com', 302, ['Cache-Control' => 'no-store']);

        $restored = Router::fromExport($router->export());

        $this->assertSame($router->export(), $restored->export());
    }

    /**
     * @return iterable<string, array{string, int, array<string, string>}>
     */
    public static function unsafeRedirects(): iterable
    {
        yield 'protocol-relative URL' => ['//evil.example', 301, []];
        yield 'script URL' => ['javascript:alert(1)', 301, []];
        yield 'other scheme' => ['ftp://files.example', 301, []];
        yield 'not a redirect status' => ['about', 200, []];
        yield 'header injection' => ['about', 301, ['X-Note' => "a\r\nSet-Cookie: x=1"]];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('unsafeRedirects')]
    public function testUnsafeRedirectsAreRefused(string $to, int $status, array $headers): void
    {
        $this->expectException(RoutingException::class);

        (new Router())->redirect('/old', $to, $status, $headers);
    }

    private function get(string $path): ResponseInterface
    {
        return $this->kernel->handle(new ServerRequest('GET', $path));
    }
}
