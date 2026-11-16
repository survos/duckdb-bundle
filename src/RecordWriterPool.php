<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** Bounded writable connections for an interleaved stream of partitioned records. */
final class RecordWriterPool
{
    /** @var array<string, RecordWriter> Staging state survives connection eviction. */
    private array $writers = [];
    /** @var array<string, true> Least recently used first. */
    private array $open = [];
    private int $opens = 0;
    private int $hits = 0;
    private int $evictions = 0;

    public function __construct(private readonly int $maxConnections = 8)
    {
        if ($maxConnections < 1) {
            throw new \InvalidArgumentException('At least one writer connection is required.');
        }
    }

    /** Do not retain a writer between calls; reacquire it so the pool can enforce its limit. */
    public function get(string $path, string $table = 'records'): RecordWriter
    {
        if (isset($this->open[$path])) {
            ++$this->hits;
            unset($this->open[$path]);
        } else {
            if (count($this->open) >= $this->maxConnections) {
                $oldest = array_key_first($this->open);
                $this->writers[$oldest]->suspend();
                unset($this->open[$oldest]);
                ++$this->evictions;
            }
            ++$this->opens;
        }
        $this->writers[$path] ??= new RecordWriter($path, table: $table);
        $this->writers[$path]->resume();
        $this->open[$path] = true;

        return $this->writers[$path];
    }

    /** Only call after the complete input stream has been consumed. */
    public function finish(string $path, array $metadata, array $evidence): void
    {
        if (!isset($this->writers[$path])) {
            throw new \LogicException('No records were routed to this partition.');
        }
        $writer = $this->get($path);
        $writer->dataset($metadata);
        $writer->finish($evidence);
        unset($this->writers[$path], $this->open[$path]);
    }

    public function stats(): array
    {
        return ['open' => count($this->open), 'partitions' => count($this->writers), 'opens' => $this->opens, 'hits' => $this->hits, 'evictions' => $this->evictions];
    }
}
