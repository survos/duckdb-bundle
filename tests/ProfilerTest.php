<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\Debug\DuckDbDataCollector;
use Survos\DuckDbBundle\Debug\QueryRecorder;
use Survos\DuckDbBundle\Debug\TraceablePdo;
use Survos\DuckDbBundle\SchemaInspector;

final class ProfilerTest extends TestCase
{
    public function testPdoCallsPreparedBindingsErrorsAndRedaction(): void
    {
        $recorder = new QueryRecorder();
        $db = new TraceablePdo('duckdb::memory:', $recorder, 'one');
        $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, title VARCHAR)');
        $insert = $db->prepare('INSERT INTO products VALUES (?, ?)');
        $id = 1;
        $title = 'private-value-not-recorded';
        $insert->bindParam(1, $id, \PDO::PARAM_INT);
        $insert->bindParam(2, $title);
        $insert->execute();
        $id = 2;
        $insert->bindParam(1, $id, \PDO::PARAM_INT);
        $insert->bindParam(2, $title);
        $insert->execute();
        $insert->execute([3, 'another-private-value']);
        self::assertSame(3, $db->query('SELECT count(*) FROM products')->fetchColumn());
        $db->beginTransaction();
        $db->rollBack();
        try {
            $db->exec('CREATE SECRET test_secret (TYPE does_not_exist, SECRET \'sensitive-secret\')');
            self::fail('Expected invalid secret type.');
        } catch (\PDOException) {
        }
        $snapshot = $recorder->snapshot();
        self::assertCount(1, $snapshot['connections']);
        self::assertSame('memory', $snapshot['connections'][0]['transport']);
        self::assertSame('duckdb::memory:', $snapshot['connections'][0]['dsn']);
        self::assertGreaterThan(0, $snapshot['connections'][0]['connectDuration']);
        self::assertSame($snapshot['connections'][0]['id'], $snapshot['queries'][0]['connectionId']);
        self::assertSame(9, $snapshot['count']);
        self::assertSame(1, $snapshot['errors']);
        self::assertSame(['exec', 'prepare', 'execute', 'execute', 'execute', 'query', 'transaction', 'transaction', 'exec'], array_column($snapshot['queries'], 'operation'));
        self::assertGreaterThan(0, $snapshot['duration']);
        self::assertStringNotContainsString('private-value', json_encode($snapshot));
        self::assertStringNotContainsString('sensitive-secret', json_encode($snapshot));
        self::assertSame('[secret statement redacted]', $snapshot['queries'][8]['sql']);
        $schema = $recorder->withoutRecording(fn () => (new SchemaInspector())->inspect($db));
        self::assertSame(9, $recorder->snapshot()['count']);
        self::assertSame(['id', 'title'], array_column($schema['columns'], 'columnName'));
        $recorder->recordSchema('one', $schema);
        $collector = new DuckDbDataCollector($recorder);
        $collector->capture();
        $restored = unserialize(serialize($collector));
        self::assertSame(9, $restored->getQueryCount());
        self::assertSame($schema, $restored->getSchemas()['one']);
        $collector->reset();
        self::assertSame(0, $recorder->snapshot()['count']);
        self::assertSame(9, $restored->getQueryCount());
    }

    public function testFetchedResultsWithoutInspectingValues(): void
    {
        $recorder = new QueryRecorder();
        $db = new TraceablePdo('duckdb::memory:', $recorder, 'results');
        $count = $db->query('SELECT count(*) FROM range(20)');
        self::assertSame(20, $count->fetchColumn());
        $all = $db->prepare('SELECT range AS id FROM range(?)');
        $all->execute([3]);
        self::assertCount(3, $all->fetchAll(\PDO::FETCH_ASSOC));
        $all->execute([1]);
        self::assertSame(0, $all->fetchObject()->id);
        $partial = $db->query('SELECT * FROM range(5)');
        $partial->fetch();
        $iterator = $db->query('SELECT * FROM range(2)');
        self::assertCount(2, iterator_to_array($iterator));
        $snapshot = $recorder->snapshot()['queries'];
        self::assertArrayNotHasKey('countValue', $snapshot[0]);
        self::assertSame(1, $snapshot[0]['resultCount']);
        self::assertNull($snapshot[1]['resultCount']);
        self::assertSame(3, $snapshot[2]['resultCount']);
        self::assertSame(1, $snapshot[3]['resultCount']);
        self::assertSame(1, $snapshot[4]['resultCount']);
        self::assertSame(2, $snapshot[5]['resultCount']);
    }

    public function testConnectionIdentityAndCredentialRedaction(): void
    {
        $recorder = new QueryRecorder();
        $first = new TraceablePdo('duckdb::memory:', $recorder, 'same');
        $second = new TraceablePdo('duckdb::memory:', $recorder, 'same');
        $first->query('SELECT 1');
        $second->query('SELECT 2');
        self::assertNotSame($first->connectionId, $second->connectionId);
        self::assertSame([$first->connectionId, $second->connectionId], array_column($recorder->snapshot()['queries'], 'connectionId'));
        $remote = $recorder->startConnection('remote', 'duckdb::memory:', 's3://bucket/file.duckdb', 'https://user:password@host?token=secret');
        $recorder->connected($remote, 1.0);
        $recorder->opened($remote, 50.0);
        $metadata = $recorder->snapshot()['connections'][2];
        self::assertSame('s3', $metadata['transport']);
        self::assertSame('s3://bucket/file.duckdb', $metadata['location']);
        self::assertSame(50.0, $metadata['openDuration']);
        self::assertStringNotContainsString('password', $metadata['endpoint']);
        self::assertStringNotContainsString('secret', $metadata['endpoint']);
        $recorder->reset();
        self::assertSame([], $recorder->snapshot()['connections']);
    }

    public function testBoundedRecordingAndSilentErrors(): void
    {
        $recorder = new QueryRecorder(1);
        $db = new TraceablePdo('duckdb::memory:', $recorder, 'limited');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        try {
            self::assertFalse($db->query('SELECT * FROM missing_table'));
        } catch (\PDOException) {
            // The native driver's current build throws even with ERRMODE_SILENT.
        }
        $db->query('SELECT 1');
        self::assertCount(1, $recorder->snapshot()['queries']);
        self::assertSame(1, $recorder->snapshot()['dropped']);
        self::assertSame(1, $recorder->snapshot()['errors']);
    }
}
