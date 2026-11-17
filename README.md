# DuckDB Bundle

Explicit named sources and read-only native PDO connections for PHP 8.5 and
Symfony 8.1/8.2, with PDO timing and schema inspection. No API Platform dependency.
No directory scanning, discovery, or connections during container compilation.

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


## Inspect snapshot fields and facets

`SnapshotInspector` queries an existing VARIANT record column directly. It never
creates JSONL, a sidecar, a persistent table or a full profile. Use a connection
from `ConnectionFactory` or `NamedSources` to keep the snapshot read-only:

```php
use Survos\DuckDbBundle\SnapshotInspector;

$db = $sources->open('archive');
$inspector = new SnapshotInspector(); // also registered for injection
$root = $inspector->fields($db, 'doc');
$children = $inspector->fields($db, 'doc', path: ['record']);
$facet = $inspector->distribution($db, 'doc', ['record', 'recordType']);
```

Fields returns `{fields: [{key, path, present}], truncated}` for the immediate
object children. Repeat with a returned path to drill down. Presence includes
explicit nulls; array contents are not traversed. Distribution returns
`{values: [{valueJson, count}], truncated}` with exact counts, ordered by frequency
then value. `valueJson` is JSON text, retaining scalar types and large numbers.
Missing and null share one bucket. Arrays and objects are counted as whole values,
not expanded into element facets. Grouping uses native DuckDB VARIANT semantics.

Paths are lists of **literal keys**: `['a.b']` selects a dotted key, while `['a', 'b']`
selects a nested key. Table/column names are single quoted identifiers, not SQL
expressions. Select a schema/catalog on the connection first if necessary. The
default column is `body`; pass `column:` to inspect another VARIANT column.
Limits bound returned fields/buckets (1–10000); they do not sample records or
bound the work needed for exact counts. Empty tables return empty lists.

With `symfony/console`, the bundle registers `duckdb:inspect`:

```sh
php bin/console duckdb:inspect /path/to/raw.duckdb doc
php bin/console duckdb:inspect /path/to/raw.duckdb doc --path=record
php bin/console duckdb:inspect archive doc --named --path=record --path=recordType --distribution --limit=20
```

The command emits JSON. Repeat `--path` for nested keys; each option is literal,
so `--path=a.b` selects the single dotted key. Without `--named`, the source is a
local path or URI opened by `ConnectionFactory`; configured S3 credentials use a
named source. Inspection requires DuckDB 2.0 VARIANT functions.

### Optional legacy profile export

Older code-generation consumers can explicitly request the existing profile shape:

```sh
composer require survos/jsonl-bundle
php bin/console duckdb:inspect /path/to/raw.duckdb doc --legacy-profile > records.profile.json
```

This calls `Inspection\LegacyProfileExporter::export($db, $input, $table, $column)`.
It requires jsonl-bundle's `Sqlite\LegacyProfile` adapter, but creates no SQLite
database. Neither jsonl-bundle nor its adapter is needed for ordinary inspection.
Without the adapter, export fails with an explicit optional-dependency message.

Export preserves the decoded `field_stats`/legacy profile contract, scalar facet
heuristics, null/presence counts, and array summaries. It scans all fields with the
old defaults (depth 6, 2000 fields, top 20 values) and normalizes scalar values to
SQLite-compatible atoms. `--limit` only controls ordinary inspection. Native
non-JSON VARIANT types need explicit mapping and are rejected by legacy export.
This is deliberately an optional exhaustive scan, not a prerequisite for browsing.
In the NARA prototype (10,592 records), direct distributions took about 2–3 ms,
while the full compatibility profile took about 9.7 seconds. These are local
warm-cache measurements, not remote performance guarantees.

## PDO profiling

Instrumentation is independent of HTTP: `Debug\QueryRecorder`, `Debug\TraceablePdo`
and `SchemaInspector` can run in a standalone console program without booting a
Symfony kernel. `Debug\DuckDbDataCollector` adapts their snapshots to Symfony's
profiler for HTTP requests and console commands.

```yaml
survos_duck_db:
    profiling: true # defaults to kernel.debug; false returns ordinary native PDO
    collect_schema: true
    max_queries: 500
```

With `symfony/web-profiler-bundle` enabled, a **DuckDB icon** toolbar item and panel show
source names, SQL, durations in milliseconds, errors, and schema snapshots.
The Connections section gives each opened PDO instance its own ID and shows the
source name, local/memory/S3 location, native DSN, target URI, S3 endpoint, open
status, and timings. Query entries reference that ID, including repeated opens of
the same named source. URI credentials and query/fragment data are redacted.

PDO construction time is separate from total open time. For S3 the native DSN is
`duckdb::memory:`, while the target is the `s3://bucket/object.duckdb` attachment;
total open time includes `httpfs` setup, secrets and `ATTACH`. Open time excludes
schema inspection and overlaps setup SQL timings, so those totals must not be
added together. A local connection's two timings are the same. Connection metadata
is recorded for actual PDO construction attempts, not configuration validation
failures that occur before construction.
In a FrameworkBundle console application, install `symfony/stopwatch` and use the standard
`php bin/console your:command --profile -vv` to save a profile and print its link.
The same collector works without registering any application HTTP routes.

The profiler times `query()`, `exec()`, `prepare()`, each statement `execute()`,
and explicit transaction calls. Preparing once and executing twice produces three
entries. Timings cover the PDO calls, not later fetching, serialization, connection
creation (reported separately), or application hydration. Counters therefore describe **PDO calls**, not
unique SQL queries. The Fetched results / affected column shows entries observed through `fetch()`,
`fetchAll()`, `fetchColumn()`, `fetchObject()`, or iteration; partial consumption
means a partial count. `fetchAll()` counts returned entries (groups/keys for grouped
or keyed fetch modes), not necessarily underlying rows. PDO's ambiguous `false`
scalar return is treated as end-of-results. No extra reads are issued and SQL is not parsed to infer totals. A `COUNT` query
returns one result; its value is not inspected. `exec()` shows the affected-row count. Bound values and ordinary result contents
are never stored. Queries beyond `max_queries` contribute to totals but their
details are dropped. SQL text is capped at 10,000 bytes per entry.

Bound parameter values and exception messages are never collected. Statements
containing `SECRET` are redacted. Other SQL text may contain literals, and schema
names can be sensitive: enable collection only where profiles are appropriately
protected. Native driver errors are rethrown unchanged to the caller. The wrapper
does not replace the driver's binding or error-mode behavior.

Schema inspection captures tables, views, columns, data types and nullability from
the currently selected database when a factory connection opens. It does not open
other configured sources. Metadata calls are excluded from timing totals, snapshots
are capped at 2,000 columns per source, and failures are reported without breaking
the connection. Snapshots are metadata only, never row samples or view definitions.
They reflect the schema at open time, not subsequent DDL. To inspect explicitly:

```php
$schema = (new SchemaInspector())->inspect($pdo);
```

Standalone usage:

```php
$recorder = new QueryRecorder();
$factory = new ConnectionFactory($recorder, new SchemaInspector());
$pdo = $factory->open('/absolute/path/catalog.duckdb', source: 'catalog');
$statement = $pdo->prepare('SELECT * FROM products WHERE year = ?');
$statement->execute([2025]);
$measurements = $recorder->snapshot();
```

Use the `Survos\DuckDbBundle` namespace for the factory/inspector and
`Survos\DuckDbBundle\Debug` for the recorder. For an existing writable workflow,
construct `TraceablePdo` directly with its DSN and options. An already-created PDO
object cannot be retroactively instrumented; callers must obtain the subclass at
construction. The factory remains read-only. Persistent connections and replacing
`PDO::ATTR_STATEMENT_CLASS` are outside this instrumentation contract.

Snapshots contain no live PDO objects. Symfony resets recording between worker
requests/messages; standalone long-running programs should call `$recorder->reset()`
between units of work. A serialized collector retains its captured data after reset.

## Minimal standalone demo

`demo/app.php` contains the example service, method-level `#[AsCommand]`, and tiny
HTTP kernel. The console branch uses `SingleCommandApplication` directly and never
boots that kernel. This follows the [single-command application example](https://github.com/symfony/symfony-docs/pull/20424)
with Symfony 8.1 method attributes. The HTTP branch is plain HTML with the toolbar;
there is no application UI framework or dependency on another application.

```sh
cd demo
composer install
php app.php --prepare
symfony server:start -d --port=8007
symfony open:local
DUCKDB_DEMO_URL=https://127.0.0.1:8007 php app.php --profile
```

`--prepare` explicitly creates the small local fixture once. Ordinary requests and
commands only read it. `--profile` saves a console profile and prints a clickable
link; it does not launch a browser automatically. HTTP and console profiles share
`demo/var/profiler`. The browser viewer is optional for recording a console profile.
The demo saves a native Symfony `Profile` directly, without inventing an HTTP request.

Set `DUCKDB_DEMO_URI` to an absolute local file or an `s3://` object containing the
demo's `products(id, title, year)` table. For S3, also set `DUCKDB_S3_ENDPOINT`,
`DUCKDB_S3_REGION`, `DUCKDB_S3_URL_STYLE`, `DUCKDB_S3_ACCESS_KEY`,
`DUCKDB_S3_SECRET_KEY`, and optionally `DUCKDB_S3_SESSION_TOKEN`. Provision `httpfs`
as described above. The demo exercises a direct query, preparation, and two bound
executions; the profiler also lists its `recent_products` view for the local fixture.
The tested native driver clears bindings after execution, so the demo rebinds before
executing again. The profiler neither retains nor substitutes parameter values.

The profiler is available from 2.45.9. Local development uses the normal mono
`link` command after installation; no Composer path or VCS overrides are required.

## Tests

Run `composer install` and `vendor/bin/phpunit -c phpunit.xml.dist` in this bundle.
The suite uses native DuckDB to check direct and prepared calls, bound values,
failures, caps, schema snapshots, serialization/reset, and rendering of HTTP and
standalone console profiles. `DUCKDB_TEST_AUTOLOAD` and `DUCKDB_TEST_TMPDIR` allow
an existing test environment and a writable temporary directory.


## Automatic Doctrine DBAL profiling

When profiling is enabled (by default in debug mode) and Doctrine DBAL is installed,
the bundle registers a `doctrine.middleware` automatically. No application driver,
connection subclass, or profiling configuration is needed. DuckDB connections are
detected from the native PDO engine, so custom DuckDB DBAL drivers/platforms also
work. Other database engines are passed through without collection.

The middleware records DBAL queries, prepare/execute calls, transactions, errors,
connection timing, schema snapshots and fetched result counts in the same DuckDB
panel as native PDO sources. Bound values are not stored. It preserves the original
driver, platform, binding and exception behavior. Connections already using the
bundle's TraceablePdo are not wrapped twice. `profiling: false` installs no middleware.

For DBAL connections, recording covers calls through DBAL; calls made directly on
an unwrapped native PDO obtained from `getNativeConnection()` bypass that layer.
Use the bundle's PDO factory for standalone PDO instrumentation. Driver connection
setup SQL is included in connection timing, but not in the DBAL query list. Failed
connections cannot be classified by their native PDO engine and are not recorded
by this middleware. The normal Doctrine profiler remains available independently.

## Raw record snapshots

`RecordWriter` keeps record bodies as `VARIANT` and lets DuckDB 2.0 shred common
fields into typed storage automatically. The default minimum shredding size is
128 rows, so small snapshots benefit too; this is independent of ingestion batch size.

Bulk writes use one JSON-array parameter and `INSERT … SELECT` per batch, with
an 8,192-record / 8 MiB encoded-payload limit (a single larger record is retained
whole). No temporary JSONL file or inferred fixed schema is required. Sequence,
nullable source IDs, table switching, writer-pool suspension, completion metadata
and checkpointed atomic publication retain the same contracts.
