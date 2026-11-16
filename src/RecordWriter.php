<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** Builds a checkpointed snapshot without exposing a partially written destination. */
final class RecordWriter
{
    private ?\PDO $db = null;
    private ?string $temporary = null;
    private array $batch = [];
    private int $rows = 0;
    private array $counts = [];
    private string $table;

    public function __construct(private readonly string $path, ConnectionFactory $factory = new ConnectionFactory(), string $table = 'records')
    {
        if (!is_dir(dirname($path))) {
            throw new \InvalidArgumentException('Create the snapshot directory before opening a writer.');
        }
        $this->temporary = $path.'.building-'.bin2hex(random_bytes(8));
        $this->db = $factory->pdo('duckdb:'.$this->temporary, 'raw-build');
        $version = ltrim((string) $this->db->query('SELECT version()')->fetchColumn(), 'v');
        if (version_compare($version, '2.0.0', '<')) {
            throw new \RuntimeException('Record snapshots require DuckDB 2.0 or newer; upgrade ext-pdo_duckdb and its embedded engine.');
        }
        $this->table = $table;
        $this->createTable($table);
        $this->db->exec('CREATE TABLE acquisition (metadata JSON)');
    }

    private function createTable(string $table): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $table) || in_array($table, ['acquisition', 'dataset'], true)) {
            throw new \InvalidArgumentException('Invalid or reserved record table name.');
        }
        if (!isset($this->counts[$table])) {
            $this->db->exec('CREATE TABLE "'.$table.'" (sequence BIGINT, source_id VARCHAR, body VARIANT)');
            $this->counts[$table] = 0;
        }
    }

    public function selectTable(string $table): void
    {
        $this->flush();
        $this->createTable($table);
        $this->table = $table;
    }

    public function dataset(array $metadata): void
    {
        $this->db->exec('CREATE TABLE dataset (body VARIANT)');
        $this->db->prepare('INSERT INTO dataset VALUES (CAST(CAST(? AS JSON) AS VARIANT))')
            ->execute([json_encode($metadata, JSON_THROW_ON_ERROR)]);
    }

    public function write(array $row, ?string $sourceId = null): void
    {
        if (null === $this->db) {
            throw new \LogicException('Snapshot writer is closed.');
        }
        $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        ++$this->rows;
        $this->batch[] = [++$this->counts[$this->table], $sourceId, $json];
        if (count($this->batch) >= 512) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ([] === $this->batch) {
            return;
        }
        $statement = $this->db->prepare('INSERT INTO "'.$this->table.'" VALUES '.implode(',', array_fill(0, count($this->batch), '(?, ?, CAST(CAST(? AS JSON) AS VARIANT))')));
        $statement->execute(array_merge(...$this->batch));
        $this->batch = [];
    }

    /** Completion describes the declared acquisition scope, including an explicit record limit. */
    public function finish(array $evidence): void
    {
        if (null === $this->db || [] === $evidence) {
            throw new \LogicException('An open writer and acquisition evidence are required.');
        }
        $this->flush();
        $statement = $this->db->prepare('INSERT INTO acquisition VALUES (CAST(? AS JSON))');
        $statement->execute([json_encode(['schema' => 1, 'complete' => true, 'rows' => $this->rows, 'tables' => $this->counts, 'source' => $evidence], JSON_THROW_ON_ERROR)]);
        $statement = null;
        $this->db->exec('CHECKPOINT');
        // Match the folio export's measured packing policy. Block size is fixed at creation,
        // so copy the completed database before hashing/sealing rather than changing it in place.
        clearstatcache(true, $this->temporary);
        $blockSize = filesize($this->temporary) < 16 * 1024 * 1024 ? 16384 : 65536;
        $packed = $this->temporary.'.packed';
        $this->db->exec('ATTACH '.$this->db->quote($packed).' AS packed (BLOCK_SIZE '.$blockSize.')');
        $database = '"'.str_replace('"', '""', (string) $this->db->query('SELECT current_database()')->fetchColumn()).'"';
        $this->db->exec('COPY FROM DATABASE '.$database.' TO packed');
        $this->db->exec('CHECKPOINT packed');
        $this->db->exec('DETACH packed');
        $this->db = null;
        if (is_file($packed.'.wal') || is_file($this->temporary.'.wal')) {
            throw new \RuntimeException('Snapshot has an unmerged WAL.');
        }
        if (!rename($packed, $this->path)) {
            throw new \RuntimeException('Cannot publish raw snapshot.');
        }
    }

    public function __destruct()
    {
        $this->db = null;
        if (null === $this->temporary) {
            return;
        }
        foreach ([$this->temporary, $this->temporary.'.wal', $this->temporary.'.packed', $this->temporary.'.packed.wal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
