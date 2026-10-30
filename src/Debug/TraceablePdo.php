<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug;

/** Native PDO subclass, preserving callers' PDO type declarations. */
final class TraceablePdo extends \PDO
{
    public readonly int $connectionId;

    public function __construct(string $dsn, private readonly QueryRecorder $recorder, private readonly string $source, array $options = [], ?string $location = null, ?string $endpoint = null)
    {
        $this->connectionId = $recorder->startConnection($source, $dsn, $location, $endpoint);
        $start = hrtime(true);
        $error = null;
        try {
            parent::__construct($dsn, options: $options);
            $this->setAttribute(self::ATTR_STATEMENT_CLASS, [TraceableStatement::class, [$recorder, $source, $this->connectionId]]);
        } catch (\Throwable $exception) {
            $error = $exception;
            throw $exception;
        } finally {
            $recorder->connected($this->connectionId, (hrtime(true) - $start) / 1e6, $error);
        }
    }

    public function finishOpen(float $milliseconds, ?\Throwable $error = null): void
    {
        $this->recorder->opened($this->connectionId, $milliseconds, $error);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        return $this->recorder->measure($this->source, 'query', $query, fn () => null === $fetchMode ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs), $this->connectionId);
    }

    public function exec(string $statement): int|false
    {
        return $this->recorder->measure($this->source, 'exec', $statement, fn () => parent::exec($statement), $this->connectionId);
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return $this->recorder->measure($this->source, 'prepare', $query, fn () => parent::prepare($query, $options), $this->connectionId);
    }

    public function beginTransaction(): bool
    {
        return $this->recorder->measure($this->source, 'transaction', 'BEGIN', fn () => parent::beginTransaction(), $this->connectionId);
    }

    public function commit(): bool
    {
        return $this->recorder->measure($this->source, 'transaction', 'COMMIT', fn () => parent::commit(), $this->connectionId);
    }

    public function rollBack(): bool
    {
        return $this->recorder->measure($this->source, 'transaction', 'ROLLBACK', fn () => parent::rollBack(), $this->connectionId);
    }
}
