<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** Creates fresh connections without discovery or pooling. */
final readonly class ConnectionFactory
{
    public function __construct(private ?Debug\QueryRecorder $recorder = null, private ?SchemaInspector $inspector = null)
    {
    }

    /** A PDO that reports to the profiler when it is on; for callers that run their own ATTACH sequence. */
    public function pdo(string $dsn, string $source, array $options = [], ?string $location = null, ?string $endpoint = null): \PDO
    {
        return null === $this->recorder ? new \PDO($dsn, options: $options) : new Debug\TraceablePdo($dsn, $this->recorder, $source, $options, $location, $endpoint);
    }

    /** Marks the end of a caller's own open sequence (ATTACH and friends) so the profiler shows its cost. */
    public function finishOpen(\PDO $db, float $milliseconds, ?\Throwable $error = null): void
    {
        if ($db instanceof Debug\TraceablePdo) {
            $db->finishOpen($milliseconds, $error);
        }
    }

    private function inspect(\PDO $db, string $source): \PDO
    {
        if (null !== $this->recorder && null !== $this->inspector) {
            try {
                $schema = $this->recorder->withoutRecording(fn () => $this->inspector->inspect($db));
            } catch (\Throwable) {
                $schema = ['error' => 'Schema inspection failed.'];
            }
            $this->recorder->recordSchema($source, $schema);
        }

        return $db;
    }

    public function open(string $uri, ?S3Configuration $s3 = null, string $source = 'default'): \PDO
    {
        if (str_starts_with($uri, 's3://')) {
            $url = parse_url($uri);
            if (false === $url || empty($url['host']) || empty($url['path']) || '/' === $url['path']
                || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['query']) || isset($url['fragment'])) {
                throw new \InvalidArgumentException('Expected s3://bucket/object.duckdb without credentials or query parameters.');
            }
            $start = hrtime(true);
            $s3 ??= new S3Configuration();
            $db = $this->pdo('duckdb::memory:', $source, location: $uri, endpoint: $s3->getEndpoint());
            $error = null;
            try {
                $db->exec('LOAD httpfs');
                $s3->configure($db, $uri);
                $db->exec('ATTACH '.$db->quote($uri).' AS source (READ_ONLY)');
                $db->exec('USE source');
            } catch (\Throwable $exception) {
                $error = $exception;
                throw $exception;
            } finally {
                if ($db instanceof Debug\TraceablePdo) {
                    $db->finishOpen((hrtime(true) - $start) / 1e6, $error);
                }
            }

            return $this->inspect($db, $source);
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

        return $this->inspect($this->pdo('duckdb:'.$uri, $source, [\PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]), $source);
    }
}
