<?php

declare(strict_types=1);

namespace Zana\Routing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a route handler's result into a response. Register implementations
 * as services tagged "routing.result_converters"; RouteDispatcher asks them
 * in order before its built-in conversions (string, array, null, ...).
 */
interface ResultConverterInterface
{
    public const string TAG = 'routing.result_converters';

    public function supports(mixed $result): bool;

    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface;
}
