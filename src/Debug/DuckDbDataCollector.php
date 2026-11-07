<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

final class DuckDbDataCollector extends DataCollector implements LateDataCollectorInterface
{
    private ?int $generation = null;

    public function __construct(private readonly QueryRecorder $recorder)
    {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->capture();
    }

    /** Also usable by a standalone console program, without a synthetic HTTP request. */
    public function capture(): void
    {
        $this->data = $this->recorder->snapshot();
        $this->generation = $this->recorder->generation();
    }

    public function lateCollect(): void
    {
        // Profile clones share the recorder. A service reset must not erase the
        // captured request or replace it with calls from a subsequent request.
        if (null === $this->generation || $this->generation === $this->recorder->generation()) {
            $this->capture();
        }
    }

    public function reset(): void
    {
        parent::reset();
        $this->generation = null;
        $this->recorder->reset();
    }

    public function getName(): string
    {
        return 'duckdb';
    }

    public function getConnections(): array
    {
        return $this->data['connections'] ?? [];
    }

    public function getQueries(): array
    {
        return $this->data['queries'] ?? [];
    }

    public function getPool(): array
    {
        return $this->data['pool'] ?? [];
    }

    public function getPoolHits(): int
    {
        return count(array_filter($this->getPool(), static fn (array $e): bool => $e['hit']));
    }

    public function getPoolMisses(): int
    {
        return count($this->getPool()) - $this->getPoolHits();
    }

    public function getSchemas(): array
    {
        return $this->data['schemas'] ?? [];
    }

    public function getQueryCount(): int
    {
        return $this->data['count'] ?? 0;
    }

    public function getErrorCount(): int
    {
        return $this->data['errors'] ?? 0;
    }

    public function getDuration(): float
    {
        return $this->data['duration'] ?? 0;
    }

    public function getDropped(): int
    {
        return $this->data['dropped'] ?? 0;
    }
}
