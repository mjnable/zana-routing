# Zana Routing

Routes, groups, attribute routes, URL generation and controller dispatch, on
top of `mj-zana/http`. Requires PHP 8.5 or later.

```
composer require mj-zana/routing
```

## Usage

Register `HttpServiceProvider` and `RoutingServiceProvider`, register your
controllers as services, and add routes in a module's `boot()`:

```php
public function register(ServiceCollectionInterface $services): void
{
    $services->transient(PatientController::class);
}

public function boot(ContainerInterface $container): void
{
    $router = $container->get(Router::class);

    $router->get('/patients/{id:\d+}', [PatientController::class, 'show'])->name('patients.show');
    $router->group('/api', function (Router $r) {
        $r->post('/bmi', CalculateBmiAction::class);
    }, middleware: [AuthenticationMiddleware::class]);
    $router->loadAttributes(MeasurementController::class);
}
```

```php
#[Route('/measurements', middleware: [AuthenticationMiddleware::class])]
final class MeasurementController
{
    public function __construct(private MeasurementRepository $measurements) {}

    #[Route('/{id:\d+}', name: 'measurements.show')]
    public function show(int $id): array                // route parameters are converted to int, float or bool
    {
        return $this->measurements->get($id)->toArray(); // arrays and objects become JSON
    }
}
```

```php
$urls->generate('patients.show', ['id' => 7], ['tab' => 'history']);   // /patients/7?tab=history
```

- **Dispatch:**
  - Handlers are called through the request scope, so their dependencies and
    route parameters are injected, and a parameter typed `ServerRequestInterface`
    receives the request.
  - Return values become responses: `null` becomes 204, a string becomes HTML,
    arrays and objects become JSON.
- **Errors:** unknown paths are 404, and wrong methods are 405 with an `Allow` header.
- **Caching:** `Router::export()` / `Router::fromExport()` cache the route table
  (closures can't be exported).

## New in 2.1

```php
$router->localized(['en' => '/about', 'fr' => '/a-propos'], [PageController::class, 'about'])->name('about');

$urls->generate('about');                       // the current request's locale
$urls->generate('about', ['_locale' => 'fr']);  // /fr/a-propos
$urls->alternates('about');                     // ['en' => '/en/about', 'fr' => '/fr/a-propos']
```

- **Route defaults** become request attributes, such as `_locale`.
- **Result converters**, services tagged `routing.result_converters`, turn
  custom results into responses.

## License

MIT — see [LICENSE](LICENSE).
