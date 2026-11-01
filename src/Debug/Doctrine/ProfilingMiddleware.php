<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Debug\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Survos\DuckDbBundle\Debug\QueryRecorder;
use Survos\DuckDbBundle\Debug\TraceablePdo;
use Survos\DuckDbBundle\SchemaInspector;

/** Optional DBAL bridge: detect the native engine, without replacing the application's driver/platform. */
final class ProfilingMiddleware implements Middleware
{
    public function __construct(private readonly QueryRecorder $recorder, private readonly ?SchemaInspector $inspector = null)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->recorder, $this->inspector) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryRecorder $recorder, private readonly ?SchemaInspector $inspector)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $start = hrtime(true);
                $connection = parent::connect($params);
                $milliseconds = (hrtime(true) - $start) / 1e6;
                $pdo = $connection->getNativeConnection();
                if (!$pdo instanceof \PDO || $pdo instanceof TraceablePdo || 'duckdb' !== $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                    return $connection;
                }
                $path = $params['path'] ?? $params['dbname'] ?? ':memory:';
                $source = 'doctrine';
                $id = $this->recorder->startConnection($source, 'duckdb:'.$path);
                $this->recorder->connected($id, $milliseconds);
                $this->recorder->opened($id, $milliseconds);
                if (null !== $this->inspector) {
                    try {
                        $schema = $this->recorder->withoutRecording(fn () => $this->inspector->inspect($pdo));
                    } catch (\Throwable) {
                        $schema = ['error' => 'Schema inspection failed.'];
                    }
                    $this->recorder->recordSchema($source, $schema);
                }

                return new TraceableConnection($connection, $this->recorder, $source, $id);
            }
        };
    }
}
