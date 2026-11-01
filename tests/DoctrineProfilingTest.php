<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use DuckDb\DBAL\Driver;
use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\Debug\Doctrine\ProfilingMiddleware;
use Survos\DuckDbBundle\Debug\QueryRecorder;
use Survos\DuckDbBundle\SchemaInspector;

final class DoctrineProfilingTest extends TestCase
{
    public function testDbalQueriesBindingsFetchesAndErrorsAreRecorded(): void
    {
        $recorder = new QueryRecorder();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new ProfilingMiddleware($recorder, new SchemaInspector())]);
        $db = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true], $configuration);
        $db->executeStatement('CREATE TABLE products (id INTEGER PRIMARY KEY, title VARCHAR)');
        $db->executeStatement('INSERT INTO products VALUES (?, ?)', [1, 'private-parameter']);
        self::assertSame('private-parameter', $db->fetchOne('SELECT title FROM products WHERE id = ?', [1]));
        self::assertCount(1, $db->fetchAllAssociative('SELECT * FROM products'));
        $db->beginTransaction();
        $db->rollBack();
        try {
            $db->fetchOne('SELECT * FROM missing_products');
            self::fail('The driver error must be preserved.');
        } catch (\Doctrine\DBAL\Exception) {
        }
        $snapshot = $recorder->snapshot();
        self::assertCount(1, $snapshot['connections']);
        self::assertSame('doctrine', $snapshot['connections'][0]['source']);
        self::assertSame(1, $snapshot['errors']);
        self::assertSame(9, $snapshot['count']);
        self::assertSame(1, $snapshot['queries'][4]['resultCount']);
        self::assertStringNotContainsString('private-parameter', json_encode($snapshot));
        self::assertSame(['exec', 'prepare', 'execute', 'prepare', 'execute', 'query', 'transaction', 'transaction', 'query'], array_column($snapshot['queries'], 'operation'));
        $db->close();
    }

    public function testSqliteIsNotCollected(): void
    {
        $recorder = new QueryRecorder();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new ProfilingMiddleware($recorder)]);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        self::assertSame(42, $db->fetchOne('SELECT 42'));
        self::assertSame(0, $recorder->snapshot()['count']);
        self::assertSame([], $recorder->snapshot()['connections']);
        $db->close();
    }
}
