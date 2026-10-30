<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** Explicit configuration only. Construction never accesses files or the network. */
final readonly class NamedSources
{
    public function __construct(private ConnectionFactory $factory, #[\SensitiveParameter] private array $sources = [])
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->sources);
    }

    public function open(string $name): \PDO
    {
        $source = $this->sources[$name] ?? throw new \OutOfBoundsException('Unknown DuckDB source.');
        $s3 = $source['s3'] ?? null;

        return $this->factory->open($source['uri'], null === $s3 ? null : new S3Configuration(
            endpoint: $s3['endpoint'] ?? 'https://s3.amazonaws.com',
            region: $s3['region'] ?? 'us-east-1',
            urlStyle: $s3['url_style'] ?? 'vhost',
            accessKey: $s3['access_key'] ?? '',
            secretKey: $s3['secret_key'] ?? '',
            sessionToken: $s3['session_token'] ?? null,
        ));
    }

    public function __debugInfo(): array
    {
        return ['names' => array_keys($this->sources)];
    }
}
