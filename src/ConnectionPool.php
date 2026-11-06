<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/**
 * Keeps opened DuckDB connections for the life of the PHP process, keyed by what the caller opened.
 *
 * Opening a remote file costs a full ATTACH (a second or more over S3); the queries after it cost milliseconds.
 * Under FPM the pool lives one request, which still collapses repeated opens of the same file inside it.
 * Under a persistent worker (FrankenPHP, Swoole, RoadRunner, messenger:consume) it spans requests, and DuckDB's
 * own metadata and httpfs caches stay warm with it.
 *
 * A pooled connection is shared by every caller in the process: treat it as read-only, fetch results before
 * handing it on, and never USE, SET or create objects on it. Do NOT tag this service kernel.reset.
 */
final class ConnectionPool
{
    /** @var array<string, array{db: \PDO, version: ?string, opened: int, connection: ?int}> least recently used first */
    private array $entries = [];
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(private readonly ?Debug\QueryRecorder $recorder = null, private readonly int $ttlSeconds = 300, private readonly int $maxConnections = 8)
    {
        if ($ttlSeconds < 0 || $maxConnections < 1) {
            throw new \InvalidArgumentException('ttl must not be negative and max_connections must be positive.');
        }
    }

    /**
     * @param \Closure(): \PDO $open    called only on a miss
     * @param ?string          $version changes when the underlying file does (mtime+size, build id, ETag); a different version reopens
     */
    public function remember(string $key, \Closure $open, ?string $version = null, string $source = 'default'): \PDO
    {
        $now = (int) (hrtime(true) / 1_000_000_000);
        $entry = $this->entries[$key] ?? null;
        if (null !== $entry && $entry['version'] === $version && (0 === $this->ttlSeconds || $now - $entry['opened'] < $this->ttlSeconds)) {
            unset($this->entries[$key]);
            $this->entries[$key] = $entry;
            ++$this->hits;
            $this->recorder?->recordPool($source, $key, true, $entry['connection']);

            return $entry['db'];
        }
        unset($this->entries[$key]);
        ++$this->misses;
        $db = $open();
        $connection = $db instanceof Debug\TraceablePdo ? $db->connectionId : null;
        $this->entries[$key] = ['db' => $db, 'version' => $version, 'opened' => $now, 'connection' => $connection];
        while (count($this->entries) > $this->maxConnections) {
            unset($this->entries[array_key_first($this->entries)]);
        }
        $this->recorder?->recordPool($source, $key, false, $connection);

        return $db;
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /** @return array{hits: int, misses: int, open: int} */
    public function stats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses, 'open' => count($this->entries)];
    }
}
