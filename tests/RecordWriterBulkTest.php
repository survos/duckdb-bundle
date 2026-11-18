<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\RecordReader;
use Survos\DuckDbBundle\RecordWriter;

final class RecordWriterBulkTest extends TestCase
{
    public function testBulkPreservesTypesIdentifiersOrderAndTableSwitches(): void
    {
        $root = sys_get_temp_dir().'/record-bulk-'.bin2hex(random_bytes(8));
        mkdir($root);
        $path = $root.'/raw.duckdb';
        try {
            $writer = new RecordWriter($path, table: 'obj');
            $payload = ['array' => [null, false, 0, 1.0, '1', [], ['nested' => 'é " \\ \n']], 'empty' => '', 'null' => null];
            for ($i = 0; $i < 16401; ++$i) {
                $writer->write(['i' => $i] + $payload, 0 === $i ? 'null' : (string) $i);
                if (9137 === $i) {
                    $writer->suspend();
                }
            }
            $writer->selectTable('doc');
            $writer->write(['other' => true], null);
            $writer->selectTable('obj');
            $writer->write(['i' => 16401] + $payload, '');
            $writer->dataset(['code' => 'test/bulk']);
            $writer->finish(['dataset' => 'test/bulk']);
            unset($writer);
            $db = (new ConnectionFactory())->open($path);
            $reader = new RecordReader($db, 'obj');
            $i = 0;
            foreach ($reader->rows() as $row) {
                self::assertEquals(['i' => $i++] + $payload, $row);
                self::assertIsFloat($row['array'][3]);
            }
            self::assertSame(16402, $i);
            self::assertSame(0, $reader->get('null')['i']);
            self::assertSame(16401, $reader->get('')['i']);
            self::assertSame(['obj' => 16402, 'doc' => 1], $reader->metadata()['tables']);
            self::assertSame([['other' => true]], iterator_to_array((new RecordReader($db, 'doc'))->rows()));
            self::assertSame(1, (int) $db->query('SELECT count(*) FROM doc WHERE source_id IS NULL')->fetchColumn());
        } finally {
            unset($writer,$reader,$db);
            foreach (glob($root.'/*') as $f) {
                unlink($f);
            }rmdir($root);
        }
    }

    public function testLargeRecordsFlushByBytesAndRetainTheWholeValue(): void
    {
        $root = sys_get_temp_dir().'/record-bytes-'.bin2hex(random_bytes(8));
        mkdir($root);
        $path = $root.'/raw.duckdb';
        try {
            $writer = new RecordWriter($path);
            $text = str_repeat('OCR text.', 600000);
            for ($i = 0; $i < 3; ++$i) {
                $writer->write(['i' => $i, 'text' => $text], (string) $i);
            }
            $writer->finish(['dataset' => 'test/bytes']);
            unset($writer);
            $reader = new RecordReader((new ConnectionFactory())->open($path));
            foreach ($reader->rows() as $row) {
                self::assertSame($text, $row['text']);
            }
            self::assertSame(3, $reader->metadata()['rows']);
        } finally {
            unset($writer,$reader);
            foreach (glob($root.'/*') as $f) {
                unlink($f);
            }rmdir($root);
        }
    }

    public function testAMemoryLimitAndThreadCountAreAppliedAndCheckedBeforeOpening(): void
    {
        $root = sys_get_temp_dir().'/record-limit-'.bin2hex(random_bytes(8));
        mkdir($root);
        $path = $root.'/raw.duckdb';
        try {
            $writer = new RecordWriter($path, table: 'obj', memoryLimit: '256MB', threads: 1);
            for ($i = 0; $i < 9000; ++$i) {
                $writer->write(['i' => $i]);
            }
            $writer->finish(['dataset' => 'test/limit']);
            unset($writer);
            $db = (new ConnectionFactory())->open($path);
            self::assertSame(9000, (int) $db->query('SELECT count(*) FROM obj')->fetchColumn());
            unset($db);
            self::assertSame([$path], glob($root.'/{,.}[!.]*', GLOB_BRACE));

            $this->expectException(\InvalidArgumentException::class);
            new RecordWriter($path, memoryLimit: "1GB'; DROP TABLE obj; --");
        } finally {
            unset($writer, $db);
            foreach (glob($root.'/*') as $f) {
                unlink($f);
            }rmdir($root);
        }
    }
}
