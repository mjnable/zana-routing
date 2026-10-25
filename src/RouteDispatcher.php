<?php

declare(strict_types=1);

namespace Zana\Routing;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Container\Contracts\ServiceResolverInterface;
use Zana\Http\Exception\NotFoundHttpException;
use Zana\Http\MiddlewarePipeline;
use Zana\Http\ResponseFactory;

/**
 * The terminal HTTP handler: matches the route, runs its middleware, and
 * calls the handler through the request scope with route parameters and the
 * request injected. Registered scoped as "http.handler".
 *
 * Route defaults and parameters become request attributes, and the URL
 * defaults while the route runs. Handler results go through the converters
 * tagged "routing.result_converters" before the built-in conversions.
 */
final readonly class RouteDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private Router $router,
        private ServiceResolverInterface $resolver,
        private ResponseFactory $responses,
        private ?UrlDefaults $urlDefaults = null,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());

        $attributes = [...$match->route->getDefaults(), ...$match->parameters];
        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }
        $request = $request
            ->withAttribute('_route', $match->route)
            ->withAttribute('_handler', $match->route->handler);

        $invoke = new class ($this, $match) implements RequestHandlerInterface {
            public function __construct(private readonly RouteDispatcher $dispatcher, private readonly RouteMatch $match)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->dispatcher->invoke($this->match, $request);
            }
        };

        $pipeline = new MiddlewarePipeline($match->route->getMiddleware(), $invoke, $this->resolver);

        return $this->urlDefaults === null
            ? $pipeline->handle($request)
            : $this->urlDefaults->run($attributes, static fn (): ResponseInterface => $pipeline->handle($request));
    }

    /**
     * @internal Called after the route middleware.
     */
    public function invoke(RouteMatch $match, ServerRequestInterface $request): ResponseInterface
    {
        $handler = $match->route->handler;
        $callable = is_string($handler) ? [$handler, '__invoke'] : $handler;

        $parameters = [...array_map('strval', $match->route->getDefaults()), ...$match->parameters];
        $result = $this->resolver->call($callable, $this->overrides($callable, $parameters, $request));

        if (!$result instanceof ResponseInterface) {
            foreach ($this->resolver->tagged(ResultConverterInterface::TAG) as $converter) {
                if (!$converter instanceof ResultConverterInterface) {
                    throw new \UnexpectedValueException('Services tagged "' . ResultConverterInterface::TAG . '" must implement ' . ResultConverterInterface::class . '; ' . $converter::class . ' does not.');
                }
                if ($converter->supports($result)) {
                    return $converter->convert($result, $request);
                }
            }
        }

        return $this->toResponse($result);
    }

    /**
     * Route parameters by name (converted to the declared scalar type) and the request
     * for parameters typed ServerRequestInterface.
     *
     * @param Closure|array{string, string} $callable
     * @param array<string, string> $parameters
     * @return array<string, mixed>
     */
    private function overrides(Closure|array $callable, array $parameters, ServerRequestInterface $request): array
    {
        $function = $callable instanceof Closure ? new \ReflectionFunction($callable) : new \ReflectionMethod($callable[0], $callable[1]);

        $overrides = [];
        foreach ($function->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

            if ($typeName !== null && is_a($typeName, ServerRequestInterface::class, true)) {
                $overrides[$name] = $request;
            } elseif (array_key_exists($name, $parameters)) {
                $overrides[$name] = self::convert($parameters[$name], $typeName);
            }
        }

        return $overrides;
    }

    private static function convert(string $value, ?string $type): mixed
    {
        $converted = match ($type) {
            'int' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            'bool' => match (strtolower($value)) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => null,
            },
            default => $value,
        };

        return $converted ?? throw new NotFoundHttpException("\"{$value}\" is not a valid route value.");
    }

    private function toResponse(mixed $result): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            $result === null => $this->responses->noContent(),
            is_string($result) => $this->responses->html($result),
            $result instanceof \JsonSerializable, is_array($result), is_scalar($result) => $this->responses->json($result),
            is_object($result) => $this->responses->json(get_object_vars($result)),
            default => throw new \UnexpectedValueException('A route handler returned ' . get_debug_type($result) . ', which cannot become a response.'),
        };
    }
}
