<?php

declare(strict_types=1);

namespace Zana\Routing\Tests;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zana\Http\ResponseFactory;
use Zana\Routing\Attribute\Route;

final class PatientDirectory
{
    /** @var array<int, string> */
    public array $names = [7 => 'Ada', 8 => 'Grace'];
}

#[Route('/patients', middleware: [TagMiddleware::class])]
final class PatientController
{
    public function __construct(private readonly PatientDirectory $directory)
    {
    }

    /**
     * @return array<int, string>
     */
    #[Route('', name: 'patients.index')]
    public function index(): array
    {
        return $this->directory->names;
    }

    /**
     * @return array{id: int, name: string, active: bool}
     */
    #[Route('/{id:\d+}', name: 'patients.show')]
    public function show(int $id, bool $active = true): array
    {
        return ['id' => $id, 'name' => $this->directory->names[$id] ?? 'unknown', 'active' => $active];
    }

    #[Route('/{id:\d+}', methods: ['DELETE'])]
    #[Route('/{id:\d+}/archive', methods: ['POST'])]
    public function remove(int $id): void
    {
    }
}

#[Route('/health', methods: ['GET'], name: 'health')]
final class HealthAction
{
    public function __invoke(ServerRequestInterface $request, ResponseFactory $responses): ResponseInterface
    {
        return $responses->text('ok ' . $request->getMethod());
    }
}

final class NotRoutable
{
}

final class TagMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withAddedHeader('X-Tags', 'tagged');
    }
}

final class GroupMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withAddedHeader('X-Tags', 'group');
    }
}

final class Measurement implements \JsonSerializable
{
    public function __construct(public readonly float $bmi)
    {
    }

    /**
     * @return array{bmi: float, category: string}
     */
    public function jsonSerialize(): array
    {
        return ['bmi' => $this->bmi, 'category' => $this->bmi < 25 ? 'normal' : 'overweight'];
    }
}

final class PlainResult
{
    public string $status = 'saved';

    private string $secret = 'hidden';

    public function secret(): string
    {
        return $this->secret;
    }
}
