<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\Command\InspectCommand;
use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\Inspection\LegacyProfileExporter;
use Survos\DuckDbBundle\Inspection\LegacyProfiler;
use Survos\DuckDbBundle\NamedSources;
use Survos\DuckDbBundle\SnapshotInspector;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SnapshotInspectorTest extends TestCase
{
    private string $file;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->file = (getenv('DUCKDB_TEST_TMPDIR') ?: sys_get_temp_dir()).'/inspect-'.bin2hex(random_bytes(6)).'.duckdb';
        $writer = new \PDO('duckdb:'.$this->file);
        $writer->exec('CREATE TABLE records (body VARIANT)');
        $insert = $writer->prepare('INSERT INTO records VALUES (CAST(CAST(? AS JSON) AS VARIANT))');
        foreach (file(__DIR__.'/Fixtures/inspection.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $insert->execute([$line]);
        }
        $writer->exec('CREATE TABLE empty_records (body VARIANT)');
        $writer->exec('CHECKPOINT');
        $insert = null;
        $writer = null;
        $this->db = (new ConnectionFactory())->open($this->file);
    }

    protected function tearDown(): void
    {
        unset($this->db);
        unlink($this->file);
    }

    public function testDrillDownPresenceAndBoundedResults(): void
    {
        $inspector = new SnapshotInspector();
        $result = $inspector->fields($this->db, limit: 2);
        self::assertCount(2, $result['fields']);
        self::assertTrue($result['truncated']);
        self::assertSame(['fields' => [['key' => 'name', 'path' => ['nested', 'name'], 'present' => 3]], 'truncated' => false], $inspector->fields($this->db, path: ['nested']));
        self::assertSame(['fields' => [], 'truncated' => false], $inspector->fields($this->db, path: ['tags']));
        self::assertSame(['fields' => [], 'truncated' => false], $inspector->fields($this->db, 'empty_records'));
    }

    public function testDistributionPreservesTypesAndCountsNullAndMissingTogether(): void
    {
        $inspector = new SnapshotInspector();
        self::assertSame(['values' => [['valueJson' => 'null', 'count' => 3], ['valueJson' => '"value"', 'count' => 1]], 'truncated' => false], $inspector->distribution($this->db, 'records', ['nullable']));
        self::assertSame(['values' => [['valueJson' => 'true', 'count' => 2]], 'truncated' => true], $inspector->distribution($this->db, 'records', ['flag'], limit: 1));
        self::assertSame(['values' => [], 'truncated' => false], $inspector->distribution($this->db, 'empty_records', ['flag']));
    }

    public function testLiteralKeysAndIdentifiersCannotInjectSql(): void
    {
        $inspector = new SnapshotInspector();
        $values = $inspector->distribution($this->db, 'records', ['a.b'])['values'];
        self::assertSame('"quoted key"', $values[1]['valueJson']);
        $values = $inspector->distribution($this->db, 'records', ['x"; DROP TABLE records; --'])['values'];
        self::assertSame([['valueJson' => 'null', 'count' => 4]], $values);
        self::assertSame(4, $this->db->query('SELECT count(*) FROM records')->fetchColumn());
    }

    public function testInspectionDoesNotChangeSnapshotAndConnectionRejectsWrites(): void
    {
        $before = hash_file('sha256', $this->file);
        (new SnapshotInspector())->distribution($this->db, 'records', ['id']);
        self::assertSame($before, hash_file('sha256', $this->file));
        self::assertFileDoesNotExist($this->file.'.db');
        $this->expectException(\PDOException::class);
        $this->db->exec('CREATE TABLE forbidden (id INTEGER)');
    }

    public function testRejectsUnboundedLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SnapshotInspector())->distribution($this->db, 'records', ['id'], limit: 0);
    }

    public function testLegacyFieldStatsMatchRecordedSqliteContract(): void
    {
        $expected = json_decode(file_get_contents(__DIR__.'/Fixtures/inspection-fields.json'), true, flags: JSON_THROW_ON_ERROR);
        $result = (new LegacyProfiler())->profile($this->db, 'records');
        self::assertSame(4, $result['rows']);
        self::assertSame(0, $result['truncated']);
        self::assertEquals($expected, $result['fields']);
    }

    public function testLegacyExportDependencyIsOptional(): void
    {
        if (!class_exists(\Survos\JsonlBundle\Sqlite\LegacyProfile::class)) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('survos/jsonl-bundle');
        }
        $profile = (new LegacyProfileExporter())->export($this->db, $this->file);
        self::assertSame(4, $profile['recordCount']);
        self::assertTrue($profile['fields']['flag']['facetCandidate']);
    }

    public function testCommandUsesNamedReadOnlySourceAndLiteralPathSegments(): void
    {
        $factory = new ConnectionFactory();
        $sources = new NamedSources($factory, ['fixture' => ['uri' => $this->file]]);
        $application = new Application();
        $command = $application->addCommand(new InspectCommand($factory, $sources, new SnapshotInspector(), new LegacyProfileExporter()));
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['source' => 'fixture', '--named' => true, '--distribution' => true, '--path' => ['a.b']]));
        $output = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('"quoted key"', $output['values'][1]['valueJson']);
    }

    public function testBundleRegistersTheInspectionCommand(): void
    {
        $directory = dirname($this->file).'/inspection-kernel-'.bin2hex(random_bytes(5));
        $kernel = new \DuckDbDemoKernel($directory);
        try {
            $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $tester = new CommandTester($application->find('duckdb:inspect'));
            self::assertSame(0, $tester->execute(['source' => $this->file, '--path' => ['nested']]));
            $result = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('name', $result['fields'][0]['key']);
        } finally {
            $kernel->shutdown();
            (new \Symfony\Component\Filesystem\Filesystem())->remove($directory);
        }
    }
}
