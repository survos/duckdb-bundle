<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\RecordReader;
use Survos\DuckDbBundle\RecordWriterPool;

final class RecordWriterPoolTest extends TestCase
{
    public function testEvictedWritersAppendAndOnlyPublishAfterFinish(): void
    {
        $root = sys_get_temp_dir().'/duckdb-writer-pool-'.bin2hex(random_bytes(6));
        mkdir($root);
        try {
            $pool = new RecordWriterPool(1);
            for ($i = 0; $i < 6; ++$i) {
                $path = $root.'/'.($i % 2).'.duckdb';
                $pool->get($path, 'obj')->write(['id' => $i], (string) $i);
                self::assertLessThanOrEqual(1, $pool->stats()['open']);
                self::assertFileDoesNotExist($path);
            }
            foreach ([0, 1] as $partition) {
                $path = $root.'/'.$partition.'.duckdb';
                $pool->finish($path, ['dataset_key' => 'test/'.$partition], ['dataset' => 'test/'.$partition]);
                $reader = new RecordReader((new ConnectionFactory())->open($path), 'obj');
                self::assertSame([['id' => $partition], ['id' => $partition + 2], ['id' => $partition + 4]], iterator_to_array($reader->rows(), false));
                self::assertSame(3, $reader->metadata()['tables']['obj']);
                unset($reader);
            }
            self::assertSame(0, $pool->stats()['open']);
            self::assertGreaterThan(0, $pool->stats()['evictions']);
        } finally {
            unset($pool);
            foreach (glob($root.'/*') as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }
}
