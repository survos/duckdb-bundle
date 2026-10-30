<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

/** Credentials are scoped to a private in-memory database and never persisted in the database. */
final readonly class S3Configuration
{
    private string $host;
    private bool $useSsl;

    public function __construct(
        string $endpoint = 'https://s3.amazonaws.com',
        private string $region = 'us-east-1',
        private string $urlStyle = 'vhost',
        #[\SensitiveParameter] private string $accessKey = '',
        #[\SensitiveParameter] private string $secretKey = '',
        #[\SensitiveParameter] private ?string $sessionToken = null,
    ) {
        $url = parse_url($endpoint);
        if (false === $url || !isset($url['scheme'], $url['host'])
            || !in_array($url['scheme'], ['http', 'https'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || (isset($url['path']) && !in_array($url['path'], ['', '/'], true))) {
            throw new \InvalidArgumentException('Expected an HTTP(S) S3 endpoint without credentials, path or query.');
        }
        if ('' === $region || !in_array($urlStyle, ['path', 'vhost'], true)
            || (('' === $accessKey) !== ('' === $secretKey))
            || (null !== $sessionToken && ('' === $sessionToken || '' === $accessKey))) {
            throw new \InvalidArgumentException('Supply a region, valid URL style and either both S3 credentials or neither.');
        }
        $this->host = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
        $this->useSsl = 'https' === $url['scheme'];
    }

    public function getEndpoint(): string
    {
        return ($this->useSsl ? 'https://' : 'http://').$this->host;
    }

    public function configure(\PDO $db, string $scope): void
    {
        $parameters = [$this->accessKey, $this->secretKey, $this->region, $this->host, $this->urlStyle, $scope];
        $sql = 'CREATE TEMPORARY SECRET source_s3 (TYPE s3, KEY_ID ?, SECRET ?, REGION ?, ENDPOINT ?, URL_STYLE ?, SCOPE ?, USE_SSL '.($this->useSsl ? 'true' : 'false');
        if (null !== $this->sessionToken) {
            $sql .= ', SESSION_TOKEN ?';
            $parameters[] = $this->sessionToken;
        }
        $db->prepare($sql.')')->execute($parameters);
        // DuckDB probes the companion WAL even for read-only remote snapshots.
        $parameters[5] = $scope.'.wal';
        $db->prepare(str_replace('CREATE TEMPORARY SECRET source_s3 ', 'CREATE TEMPORARY SECRET source_s3_wal ', $sql).')')->execute($parameters);
    }

    public function __debugInfo(): array
    {
        return ['endpoint' => $this->host, 'region' => $this->region, 'urlStyle' => $this->urlStyle];
    }
}
