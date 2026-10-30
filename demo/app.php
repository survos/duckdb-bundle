<?php

declare(strict_types=1);

use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\Debug\DuckDbDataCollector;
use Survos\DuckDbBundle\Debug\QueryRecorder;
use Survos\DuckDbBundle\NamedSources;
use Survos\DuckDbBundle\SchemaInspector;
use Survos\DuckDbBundle\SurvosDuckDbBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Tests may supply their own Composer loader before including this single file.
if (!class_exists(ConnectionFactory::class)) {
    require __DIR__.'/vendor/autoload.php';
}

final readonly class DuckDbDemoService
{
    public function __construct(private NamedSources $sources, private QueryRecorder $recorder, private string $directory)
    {
    }

    public static function sources(string $directory): array
    {
        $uri = getenv('DUCKDB_DEMO_URI') ?: $directory.'/var/example.duckdb';
        $source = ['uri' => $uri];
        if (str_starts_with($uri, 's3://')) {
            $source['s3'] = [
                'endpoint' => getenv('DUCKDB_S3_ENDPOINT') ?: 'https://s3.amazonaws.com',
                'region' => getenv('DUCKDB_S3_REGION') ?: 'us-east-1',
                'url_style' => getenv('DUCKDB_S3_URL_STYLE') ?: 'vhost',
                'access_key' => getenv('DUCKDB_S3_ACCESS_KEY') ?: '',
                'secret_key' => getenv('DUCKDB_S3_SECRET_KEY') ?: '',
                'session_token' => getenv('DUCKDB_S3_SESSION_TOKEN') ?: null,
            ];
        }

        return ['example' => $source];
    }

    public function rows(): array
    {
        $db = $this->sources->open('example');
        $db->query('SELECT count(*) FROM products')->fetchColumn();
        $statement = $db->prepare('SELECT id, title, year FROM products WHERE year >= ? ORDER BY id');
        $year = 2024;
        $statement->bindParam(1, $year, PDO::PARAM_INT);
        $statement->execute();
        $statement->fetchAll(PDO::FETCH_ASSOC);
        $year = 2025;
        $statement->bindParam(1, $year, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    #[AsCommand('duckdb:demo', 'Read a named DuckDB source and optionally save a PDO profile')]
    public function run(SymfonyStyle $io, #[Option('Create the local demo fixture once')] bool $prepare = false, #[Option('Save a console profile without booting an HTTP kernel')] bool $profile = false): int
    {
        if ($prepare) {
            $path = $this->directory.'/var/example.duckdb';
            if (!is_file($path)) {
                $fs = new Filesystem();
                $fs->mkdir(dirname($path));
                $db = new PDO('duckdb:'.$path);
                $db->exec("CREATE TABLE products (id VARCHAR PRIMARY KEY, title VARCHAR, year INTEGER); INSERT INTO products VALUES ('a', 'Blue notebook', 2024), ('b', 'Blue pencil', 2025); CREATE VIEW recent_products AS SELECT * FROM products WHERE year >= 2025; CHECKPOINT");
                $db = null;
            }
            $io->success('Local demo fixture is ready.');

            return Command::SUCCESS;
        }
        $exitCode = Command::SUCCESS;
        try {
            $io->table(['id', 'title', 'year'], array_map('array_values', $this->rows()));
        } catch (Throwable $error) {
            $exitCode = Command::FAILURE;
            $io->error($error::class.' ('.$error->getCode().')');
        } finally {
            if ($profile) {
                $collector = new DuckDbDataCollector($this->recorder);
                $collector->capture();
                $saved = new Profile(bin2hex(random_bytes(3)));
                $saved->setTime(time());
                $saved->setMethod('CLI');
                $saved->setUrl('duckdb:demo');
                $saved->setIp('DuckDB standalone demo');
                $saved->setVirtualType('command');
                $saved->setStatusCode($exitCode);
                $saved->setHasErrors(Command::SUCCESS !== $exitCode);
                $saved->addCollector($collector);
                (new FileProfilerStorage('file:'.$this->directory.'/var/profiler'))->write($saved);
                $url = rtrim(getenv('DUCKDB_DEMO_URL') ?: 'http://127.0.0.1:8000', '/').'/_profiler/'.$saved->getToken().'?panel=duckdb';
                $io->writeln(sprintf('Profile: <href=%s>%s</>', $url, $url));
            }
        }

        return $exitCode;
    }

    public function selectProfilePanel(Symfony\Component\HttpKernel\Event\RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('_profiler' === $request->attributes->get('_route') && !$request->query->has('panel')) {
            $request->query->set('panel', 'duckdb');
        }
    }

    public function page(): Response
    {
        $rows = htmlspecialchars(json_encode($this->rows(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return new Response('<!doctype html><html><head><title>DuckDB PDO demo</title></head><body><h1>DuckDB PDO demo</h1><p>One direct query, one prepare, two executions. Open DuckDB in the toolbar for timings and schema.</p><pre>'.$rows.'</pre><p><a href="/_profiler/">Saved HTTP and console profiles</a></p></body></html>');
    }
}

final class DuckDbDemoKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $directory = __DIR__)
    {
        parent::__construct('dev', true);
    }

    public function getProjectDir(): string
    {
        return $this->directory;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new WebProfilerBundle();
        yield new SurvosDuckDbBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'local-duckdb-demo', 'router' => ['utf8' => true], 'profiler' => ['enabled' => true, 'collect' => true, 'dsn' => 'file:%kernel.project_dir%/var/profiler']]);
        $container->extension('twig', []);
        $container->extension('web_profiler', ['toolbar' => true]);
        $container->extension('survos_duck_db', ['profiling' => true, 'sources' => DuckDbDemoService::sources($this->directory)]);
        // Console is deliberately a standalone SingleCommandApplication, not a kernel command.
        $container->services()->set(DuckDbDemoService::class, DuckDbDemoService::class)->autowire()->arg('$directory', $this->directory)->public()
            ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'selectProfilePanel', 'priority' => 0]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('demo', '/')->controller([DuckDbDemoService::class, 'page']);
        $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')->prefix('/_wdt');
        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
    }
}

if (defined('DUCKDB_DEMO_HTTP') || __FILE__ === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    if ('cli' === PHP_SAPI) {
        $recorder = new QueryRecorder();
        $sources = new NamedSources(new ConnectionFactory($recorder, new SchemaInspector()), DuckDbDemoService::sources(__DIR__));
        $demo = new DuckDbDemoService($sources, $recorder, __DIR__);
        exit((new SingleCommandApplication())->setCode($demo->run(...))->run());
    }
    $kernel = new DuckDbDemoKernel();
    $request = Request::createFromGlobals();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
}
