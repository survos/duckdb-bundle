<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** One connection per scan; accepts a factory-opened local or S3 snapshot. */
final readonly class RecordReader
{
    public function __construct(private \PDO $db, private string $table = 'records')
    {
    }

    public function metadata(): array
    {
        $json = $this->db->query('SELECT CAST(metadata AS VARCHAR) FROM acquisition')->fetchColumn();
        if (false === $json) {
            throw new \RuntimeException('Raw snapshot has no acquisition evidence.');
        }
        $meta = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (1 !== $meta['schema'] || true !== $meta['complete']) {
            throw new \RuntimeException('Unsupported or incomplete raw snapshot.');
        }

        return $meta;
    }

    public function rows(): \Generator
    {
        $this->metadata();
        $statement = $this->db->query('SELECT CAST(body::JSON AS VARCHAR) FROM '.$this->identifier().' ORDER BY sequence');
        while (false !== $json = $statement->fetchColumn()) {
            yield json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    public function get(string $sourceId): ?array
    {
        $statement = $this->db->prepare('SELECT CAST(body::JSON AS VARCHAR) FROM '.$this->identifier().' WHERE source_id = ? ORDER BY sequence DESC LIMIT 1');
        $statement->execute([$sourceId]);
        $json = $statement->fetchColumn();

        return false === $json ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function identifier(): string
    {
        return '"'.str_replace('"', '""', $this->table).'"';
    }
}
