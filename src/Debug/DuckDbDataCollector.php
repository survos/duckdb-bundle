<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

final class DuckDbDataCollector extends DataCollector implements LateDataCollectorInterface
{
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
    }

    public function lateCollect(): void
    {
        $this->capture();
    }

    public function reset(): void
    {
        parent::reset();
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
