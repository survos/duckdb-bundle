<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** Creates fresh connections without discovery or pooling. */
final readonly class ConnectionFactory
{
    public function open(string $uri, ?S3Configuration $s3 = null): \PDO
    {
        if (str_starts_with($uri, 's3://')) {
            $url = parse_url($uri);
            if (false === $url || empty($url['host']) || empty($url['path']) || '/' === $url['path']
                || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['query']) || isset($url['fragment'])) {
                throw new \InvalidArgumentException('Expected s3://bucket/object.duckdb without credentials or query parameters.');
            }
            $db = new \PDO('duckdb::memory:');
            $db->exec('LOAD httpfs');
            ($s3 ?? new S3Configuration())->configure($db, $uri);
            $db->exec('ATTACH '.$db->quote($uri).' AS source (READ_ONLY)');
            $db->exec('USE source');

            return $db;
        }
        if (str_contains($uri, '://') || ':memory:' === $uri || '' === $uri) {
            throw new \InvalidArgumentException('Expected a local DuckDB file or an s3:// URI.');
        }
        if (null !== $s3) {
            throw new \InvalidArgumentException('S3 settings require an s3:// URI.');
        }
        if (!is_file($uri)) {
            throw new \RuntimeException('The configured DuckDB file does not exist.');
        }

        return new \PDO('duckdb:'.$uri, options: [\PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]);
    }
}
