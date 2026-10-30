# DuckDB Bundle

Explicit named sources and read-only native PDO connections for PHP 8.5 and
Symfony 8.1/8.2. No API Platform dependency. No directory scanning, discovery,
profiler, or connections during container compilation.

## Install

```sh
composer require survos/duckdb-bundle
```

Requires `ext-pdo` and `ext-pdo_duckdb`, using the native
[PDO driver](https://github.com/thomas-0816/pdo-duckdb-php) with DuckDB 2.0.
Composer does not install the extension; installing the DuckDB CLI is insufficient.
Check the PHP runtime serving the application:

```sh
php --ri pdo_duckdb
php -r '$db = new PDO("duckdb::memory:"); echo $db->query("SELECT version()")->fetchColumn(), PHP_EOL;'
composer check-platform-reqs
```

The extension version and embedded engine version are distinct. Pin the driver
distribution in deployment; Composer checks extension presence, not engine version.
Flex registers `Survos\DuckDbBundle\SurvosDuckDbBundle`; register it manually if
not using Flex. The kit bundle is registered through `RequiredBundle`.

## Configure named sources

```yaml
# config/packages/survos_duck_db.yaml
survos_duck_db:
    sources:
        example:
            uri: '%kernel.project_dir%/var/example.duckdb'
        archive:
            uri: 's3://my-bucket/snapshots/catalog.duckdb'
            s3:
                endpoint: 'https://s3.us-east-1.amazonaws.com'
                region: us-east-1
                url_style: vhost # path for many S3-compatible services
                access_key: '%env(DUCKDB_S3_ACCESS_KEY)%'
                secret_key: '%env(DUCKDB_S3_SECRET_KEY)%'
                # session_token: '%env(DUCKDB_S3_SESSION_TOKEN)%'
```

Inject `Survos\DuckDbBundle\NamedSources`, then call `$sources->open('example')`.
Each call opens a fresh PDO connection. Unknown names throw `OutOfBoundsException`.
Names never become filesystem paths; only configured URIs are opened. Local paths
should be absolute. Missing files are rejected rather than created.

For standalone PHP, use `ConnectionFactory::open($uri, $optionalS3Configuration)`.
Local files use the driver's `access_mode=read_only` setting. S3 uses an in-memory
connection, temporary secrets in that private in-memory database, and `ATTACH ... (READ_ONLY)` followed by
`USE source`. The attachment is read-only; the surrounding in-memory connection is
not a general SQL sandbox. Only trusted code should receive these PDO connections.

Install DuckDB's `httpfs` extension for the same engine and runtime user before
serving S3 requests (`INSTALL httpfs` during provisioning). This bundle calls
`LOAD httpfs`, never `INSTALL`. S3 must support random range reads of the complete
DuckDB file. Use an immutable, checkpointed snapshot without a pending WAL.
Endpoint URLs cannot contain credentials, a path, or query parameters.

Omit both credential fields for anonymous public objects. Otherwise supply both;
temporary credentials can include a session token. Secrets cover the object and its companion WAL probe. Credentials are not persisted
in the database. Automatic AWS credential-chain discovery is not implemented.
Keep application debug pages and configuration diagnostics private.

Multiple processes can read a local file read-only; do not have another process
write that file concurrently. See [DuckDB concurrency](https://duckdb.org/docs/stable/connect/concurrency).

## JSON imports

Opening a database does not import adjacent JSON files. DuckDB can query compressed
JSON directly or materialize it explicitly through a separate writable connection:

```sql
SELECT * FROM read_json('products.json.gz');
CREATE TABLE products AS SELECT * FROM read_json('products.json.gz');
```

Compression is inferred from the filename. A view over `read_json` reads the source
when queried; a table stores a snapshot. Define and validate a stable primary key
before exposing imported data as API items. See [DuckDB JSON loading](https://duckdb.org/docs/stable/data/json/loading_json).

For an executable import example and read-only API, see the independent `demo/`
directory in [duckdb-api-bundle](https://github.com/survos/duckdb-api-bundle).
