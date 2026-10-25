<?php

declare(strict_types=1);

namespace Zana\Routing\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zana\Container\Contracts\ServiceCollectionInterface;
use Zana\Core\Application;
use Zana\Http\HttpKernel;
use Zana\Http\HttpServiceProvider;
use Zana\Routing\ResultConverterInterface;
use Zana\Routing\Router;
use Zana\Routing\RoutingException;
use Zana\Routing\RoutingServiceProvider;
use Zana\Routing\UrlDefaults;
use Zana\Routing\UrlGenerator;

final class LocalizedRoutesTest extends TestCase
{
    private Router $router;

    private UrlGenerator $urls;

    private HttpKernel $kernel;

    protected function setUp(): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-routing-localized')
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class)
            ->withServices(static fn (ServiceCollectionInterface $s) => $s
                ->singleton(Greeting::class)
                ->singleton(GreetingConverter::class)
                ->tag(ResultConverterInterface::TAG, GreetingConverter::class))
            ->build()
            ->container();

        $this->router = $container->get(Router::class);
        $this->urls = $container->get(UrlGenerator::class);
        $this->kernel = $container->get(HttpKernel::class);

        $this->router->localized(['en' => '/about', 'fr' => '/a-propos'], static fn (ServerRequestInterface $request, UrlGenerator $urls): array => [
            'locale' => $request->getAttribute('_locale'),
            'self' => $urls->generate('about'),
            'other' => $urls->alternates('about'),
        ])->name('about');
        $this->router->localized(['en' => '/', 'fr' => '/'], static fn (): string => 'home')->name('home');
        $this->router->localized(['en' => '/news/{slug}', 'fr' => '/actualites/{slug}'], static fn (string $slug, UrlGenerator $urls): array => [
            'slug' => $slug,
            'alternates' => $urls->alternates('news'),
        ])->name('news')->where('slug', '[a-z-]+');
    }

    public function testEachLocaleHasItsPathAndName(): void
    {
        $this->assertSame('/en/about', $this->router->named('about.en')->path);
        $this->assertSame('/fr/a-propos', $this->router->named('about.fr')->path);
        $this->assertSame('/en', $this->router->named('home.en')->path);
        $this->assertSame(['_locale' => 'fr'], $this->router->named('about.fr')->getDefaults());
    }

    public function testTheMatchedLocaleBecomesARequestAttributeAndTheUrlDefault(): void
    {
        $body = $this->json($this->kernel->handle(new ServerRequest('GET', '/fr/a-propos')));

        $this->assertSame([
            'locale' => 'fr',
            'self' => '/fr/a-propos',
            'other' => ['en' => '/en/about', 'fr' => '/fr/a-propos'],
        ], $body);
    }

    public function testAlternatesKeepTheCurrentParameters(): void
    {
        $body = $this->json($this->kernel->handle(new ServerRequest('GET', '/fr/actualites/lancement')));

        $this->assertSame(['en' => '/en/news/lancement', 'fr' => '/fr/actualites/lancement'], $body['alternates']);
    }

    public function testOutsideARequestTheFirstLocaleOrTheGivenOneIsUsed(): void
    {
        $this->assertSame('/en/about', $this->urls->generate('about'));
        $this->assertSame('/fr/a-propos', $this->urls->generate('about', ['_locale' => 'fr']));
        $this->assertSame('/fr/actualites/hello?page=2', $this->urls->generate('news', ['_locale' => 'fr', 'slug' => 'hello', 'page' => 2]));
    }

    public function testUnknownLocalesAndPlainNamesAreRejected(): void
    {
        $this->router->get('/contact', static fn (): string => 'contact')->name('contact');

        try {
            $this->urls->generate('about', ['_locale' => 'sw']);
            $this->fail('Expected a RoutingException.');
        } catch (RoutingException $e) {
            $this->assertSame('Route about has no variant for the locale sw.', $e->getMessage());
        }

        $this->expectExceptionMessage('Route contact is not localised.');
        $this->urls->alternates('contact');
    }

    public function testUrlDefaultsAreRestoredAfterTheRequest(): void
    {
        $defaults = new UrlDefaults();

        $inside = $defaults->run(['_locale' => 'fr'], static fn (): array => $defaults->all());

        $this->assertSame(['_locale' => 'fr'], $inside);
        $this->assertSame([], $defaults->all());

        try {
            $defaults->run(['_locale' => 'fr'], static fn () => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $defaults->all(), 'also after an exception');
    }

    public function testLocalizedRoutesSurviveTheRouteCache(): void
    {
        $router = new Router();
        $router->localized(['en' => '/about', 'fr' => '/a-propos'], [PageAction::class, 'show'])->name('about');

        $restored = Router::fromExport($router->export());

        $this->assertSame('/fr/a-propos', (new UrlGenerator($restored))->generate('about', ['_locale' => 'fr']));
        $this->assertSame(['_locale' => 'fr'], $restored->match('GET', '/fr/a-propos')->route->getDefaults());
    }

    public function testResultConvertersTurnCustomResultsIntoResponses(): void
    {
        $this->router->get('/greet', static fn (Greeting $greeting): Greeting => $greeting);

        $response = $this->kernel->handle(new ServerRequest('GET', '/greet'));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('Hello from a converter', (string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));

        /** @var array<string, mixed> $data */
        return $data;
    }
}

final class Greeting
{
    public string $text = 'Hello from a converter';
}

final class GreetingConverter implements ResultConverterInterface
{
    public function supports(mixed $result): bool
    {
        return $result instanceof Greeting;
    }

    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        assert($result instanceof Greeting);

        return new Response(202, [], $result->text);
    }
}

final class PageAction
{
    public function show(): string
    {
        return 'page';
    }
}
