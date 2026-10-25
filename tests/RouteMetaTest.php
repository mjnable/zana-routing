<?php

declare(strict_types=1);

namespace Zana\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Zana\Core\Application;
use Zana\Http\HttpServiceProvider;
use Zana\Routing\Attribute\Route as RouteAttribute;
use Zana\Routing\Router;
use Zana\Routing\RoutingServiceProvider;
use Zana\Routing\UrlGenerator;

final class RouteMetaTest extends TestCase
{
    public function testRoutesCarryMetadataThroughAttributesLocalizationAndTheCache(): void
    {
        $router = new Router();
        $router->get('/legal', [MetaPage::class, 'legal'])->name('legal')->meta(['sitemap' => false]);
        $router->localized(['en' => '/about', 'fr' => '/a-propos'], [MetaPage::class, 'about'])->name('about')->meta(['sitemap' => true, 'priority' => 0.8]);
        $router->loadAttributes(MetaPage::class);

        $restored = Router::fromExport($router->export());

        $this->assertSame(['sitemap' => false], $restored->named('legal')->getMeta());
        $this->assertSame(['sitemap' => true, 'priority' => 0.8], $restored->named('about.fr')->getMeta());
        $this->assertSame(['sitemap' => true, 'changefreq' => 'weekly'], $restored->named('news')->getMeta());

        $inSitemap = array_filter($restored->routes(), static fn ($route): bool => ($route->getMeta()['sitemap'] ?? false) === true);
        $this->assertSame(['/en/about', '/fr/a-propos', '/news'], array_values(array_map(static fn ($route): string => $route->path, $inSitemap)));
    }

    public function testGeneratedUrlsStartWithTheBasePath(): void
    {
        $container = Application::configure(sys_get_temp_dir() . '/zana-routing-base')
            ->withEnvironment('testing')
            ->withConfiguration(['http.base_path' => '/shop'])
            ->withProviders(HttpServiceProvider::class, RoutingServiceProvider::class)
            ->build()
            ->container();
        $router = $container->get(Router::class);
        $urls = $container->get(UrlGenerator::class);
        assert($router instanceof Router && $urls instanceof UrlGenerator);

        $router->get('/cart', static fn (): string => 'cart')->name('cart');
        $router->localized(['en' => '/about', 'fr' => '/a-propos'], static fn (): string => 'about')->name('about');

        $this->assertSame('/shop/cart?step=2', $urls->generate('cart', ['step' => 2]));
        $this->assertSame(['en' => '/shop/en/about', 'fr' => '/shop/fr/a-propos'], $urls->alternates('about'));
    }
}

final class MetaPage
{
    public function legal(): string
    {
        return 'legal';
    }

    public function about(): string
    {
        return 'about';
    }

    #[RouteAttribute('/news', name: 'news', meta: ['sitemap' => true, 'changefreq' => 'weekly'])]
    public function news(): string
    {
        return 'news';
    }
}
