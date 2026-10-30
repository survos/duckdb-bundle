<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug;

/** In-memory PDO measurements; no Symfony kernel, request or logging dependency. */
final class QueryRecorder
{
    private array $queries = [];
    private array $connections = [];
    private int $nextConnectionId = 0;
    private int $nextEntryId = 0;
    private array $entryIndexes = [];
    private array $schemas = [];
    private int $count = 0;
    private int $errors = 0;
    private float $duration = 0;
    private int $suspended = 0;

    public function __construct(private readonly int $maxQueries = 500)
    {
        if ($maxQueries < 1) {
            throw new \InvalidArgumentException('maxQueries must be positive.');
        }
    }

    public function measure(string $source, string $operation, string $sql, \Closure $call, ?int $connectionId = null, ?TraceableStatement $statement = null): mixed
    {
        if ($this->suspended) {
            return $call();
        }
        $entryId = ++$this->nextEntryId;
        $result = null;
        $start = hrtime(true);
        $error = null;
        try {
            $result = $call();
            if (false === $result) {
                $error = 'PDO returned false';
            }

            if ('query' === $operation && $result instanceof TraceableStatement) {
                $result->setProfileEntry($entryId);
            } elseif ('execute' === $operation && $statement) {
                $statement->setProfileEntry($entryId);
            }

            return $result;
        } catch (\Throwable $exception) {
            // Exception messages may contain parameter values or credentials.
            $error = $exception::class.' ('.$exception->getCode().')';
            throw $exception;
        } finally {
            $milliseconds = (hrtime(true) - $start) / 1e6;
            ++$this->count;
            $this->duration += $milliseconds;
            $this->errors += (int) (null !== $error);
            if (count($this->queries) < $this->maxQueries) {
                // Never expose CREATE SECRET text, even with inline credentials.
                $sql = preg_match('/\bSECRET\b/i', $sql) ? '[secret statement redacted]' : substr($sql, 0, 10000);
                $this->entryIndexes[$entryId] = count($this->queries);
                $this->queries[] = ['id' => $entryId, 'resultCount' => 'exec' === $operation && is_int($result) ? $result : null, 'connectionId' => $connectionId, 'source' => $source, 'operation' => $operation, 'sql' => $sql, 'duration' => $milliseconds, 'error' => $error];
            }
        }
    }

    public function withoutRecording(\Closure $call): mixed
    {
        ++$this->suspended;
        try {
            return $call();
        } finally {
            --$this->suspended;
        }
    }

    public function recordResult(int $entryId, int $count): void
    {
        if (!isset($this->entryIndexes[$entryId])) {
            return;
        }
        $entry = &$this->queries[$this->entryIndexes[$entryId]];
        $entry['resultCount'] = ($entry['resultCount'] ?? 0) + $count;
    }

    public function startConnection(string $source, string $dsn, ?string $location = null, ?string $endpoint = null): int
    {
        $id = ++$this->nextConnectionId;
        $location ??= str_starts_with($dsn, 'duckdb:') ? substr($dsn, 7) : $dsn;
        $this->connections[$id] = [
            'id' => $id, 'source' => $source,
            'transport' => str_starts_with($location, 's3://') ? 's3' : (':memory:' === $location ? 'memory' : 'local'),
            'dsn' => $this->safeLocation($dsn), 'location' => $this->safeLocation($location),
            'endpoint' => null === $endpoint ? null : $this->safeLocation($endpoint),
            'connectDuration' => 0.0, 'openDuration' => 0.0, 'status' => 'opening', 'error' => null,
        ];

        return $id;
    }

    public function connected(int $id, float $milliseconds, ?\Throwable $error = null): void
    {
        $this->connections[$id]['connectDuration'] = $milliseconds;
        $this->opened($id, $milliseconds, $error);
    }

    public function opened(int $id, float $milliseconds, ?\Throwable $error = null): void
    {
        // A connection retained across an explicit recorder reset has no new open event.
        if (!isset($this->connections[$id])) {
            return;
        }
        $this->connections[$id]['openDuration'] = $milliseconds;
        $this->connections[$id]['status'] = null === $error ? 'ready' : 'failed';
        $this->connections[$id]['error'] = null === $error ? null : $error::class.' ('.$error->getCode().')';
    }

    private function safeLocation(string $value): string
    {
        if (str_contains($value, '://')) {
            $value = preg_replace('~(://)[^/@]+@~', '$1[redacted]@', $value);
            $value = preg_replace('~[?#].*$~', '?[redacted]', $value);
        }

        return preg_replace('/((?:password|secret|token|access_key|key_id)=)[^;]+/i', '$1[redacted]', $value);
    }

    public function recordSchema(string $source, array $schema): void
    {
        $this->schemas[$source] = $schema;
    }

    public function snapshot(): array
    {
        return ['connections' => array_values($this->connections), 'queries' => $this->queries, 'schemas' => $this->schemas, 'count' => $this->count, 'errors' => $this->errors, 'duration' => $this->duration, 'dropped' => $this->count - count($this->queries)];
    }

    public function reset(): void
    {
        $this->queries = $this->schemas = $this->connections = $this->entryIndexes = [];
        $this->count = $this->errors = 0;
        $this->duration = 0;
    }
}
