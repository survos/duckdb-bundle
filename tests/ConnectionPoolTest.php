<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\ConnectionPool;
use Survos\DuckDbBundle\Debug\QueryRecorder;

final class ConnectionPoolTest extends TestCase
{
    private function open(): \Closure
    {
        return static fn (): \PDO => new \PDO('duckdb::memory:');
    }

    public function testSameKeyReusesTheConnection(): void
    {
        $pool = new ConnectionPool();
        $opens = 0;
        $open = function () use (&$opens): \PDO {
            ++$opens;

            return new \PDO('duckdb::memory:');
        };
        $a = $pool->remember('a', $open);
        self::assertSame($a, $pool->remember('a', $open));
        self::assertNotSame($a, $pool->remember('b', $open));
        self::assertSame(2, $opens);
        self::assertSame(['hits' => 1, 'misses' => 2, 'open' => 2], $pool->stats());
    }

    public function testAChangedVersionReopens(): void
    {
        $pool = new ConnectionPool();
        $first = $pool->remember('f', $this->open(), 'v1');
        self::assertSame($first, $pool->remember('f', $this->open(), 'v1'));
        self::assertNotSame($first, $pool->remember('f', $this->open(), 'v2'));
    }

    public function testTheLeastRecentlyUsedConnectionIsDropped(): void
    {
        $pool = new ConnectionPool(null, 300, 2);
        $a = $pool->remember('a', $this->open());
        $pool->remember('b', $this->open());
        $pool->remember('a', $this->open());
        $pool->remember('c', $this->open());
        self::assertSame($a, $pool->remember('a', $this->open()), 'a was used last, so b went');
        self::assertSame(2, $pool->stats()['open']);
    }

    public function testAFailedOpenIsNotKept(): void
    {
        $pool = new ConnectionPool();
        try {
            $pool->remember('x', static fn (): \PDO => throw new \RuntimeException('down'));
            self::fail('expected the open failure');
        } catch (\RuntimeException) {
        }
        self::assertSame(0, $pool->stats()['open']);
        self::assertInstanceOf(\PDO::class, $pool->remember('x', $this->open()));
    }

    public function testLookupsAreRecordedForTheProfiler(): void
    {
        $recorder = new QueryRecorder();
        $pool = new ConnectionPool($recorder);
        $pool->remember('s3://b/o.dfolio', $this->open(), null, 'folio');
        $pool->remember('s3://b/o.dfolio', $this->open(), null, 'folio');
        $recorded = $recorder->snapshot()['pool'];
        self::assertSame([false, true], array_column($recorded, 'hit'));
    }

    /** Sharing is only safe if a half-read result survives another statement on the same connection. */
    public function testInterleavedStatementsOnOneSharedConnection(): void
    {
        $db = (new ConnectionPool())->remember('m', $this->open());
        $db->exec('CREATE TABLE t AS SELECT range AS n FROM range(5000)');
        $a = $db->query('SELECT n FROM t ORDER BY n');
        self::assertSame(0, (int) $a->fetchColumn());
        $b = $db->query('SELECT count(*) FROM t');
        self::assertSame(5000, (int) $b->fetchColumn());
        self::assertSame(1, (int) $a->fetchColumn());
    }
}
