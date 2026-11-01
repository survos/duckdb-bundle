<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Survos\DuckDbBundle\Debug\QueryRecorder;

final class TraceableConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly QueryRecorder $recorder, private readonly string $source, private readonly int $id)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $statement = $this->recorder->measure($this->source, 'prepare', $sql, fn () => parent::prepare($sql), $this->id);

        return new class($statement, $this->recorder, $this->source, $this->id, $sql) extends AbstractStatementMiddleware {
            public function __construct(Statement $statement, private readonly QueryRecorder $recorder, private readonly string $source, private readonly int $id, private readonly string $sql)
            {
                parent::__construct($statement);
            }

            public function execute(): Result
            {
                return $this->recorder->measure($this->source, 'execute', $this->sql,
                    fn () => new TraceableResult(parent::execute(), $this->recorder), $this->id);
            }
        };
    }

    public function query(string $sql): Result
    {
        return $this->recorder->measure($this->source, 'query', $sql, fn () => new TraceableResult(parent::query($sql), $this->recorder), $this->id);
    }

    public function exec(string $sql): int|string
    {
        return $this->recorder->measure($this->source, 'exec', $sql, fn () => parent::exec($sql), $this->id);
    }

    public function beginTransaction(): void
    {
        $this->recorder->measure($this->source, 'transaction', 'BEGIN', fn () => parent::beginTransaction(), $this->id);
    }

    public function commit(): void
    {
        $this->recorder->measure($this->source, 'transaction', 'COMMIT', fn () => parent::commit(), $this->id);
    }

    public function rollBack(): void
    {
        $this->recorder->measure($this->source, 'transaction', 'ROLLBACK', fn () => parent::rollBack(), $this->id);
    }
}
