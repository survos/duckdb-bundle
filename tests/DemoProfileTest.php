<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\ConnectionFactory;
use Survos\DuckDbBundle\Debug\QueryRecorder;
use Survos\DuckDbBundle\NamedSources;
use Survos\DuckDbBundle\SchemaInspector;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;

final class DemoProfileTest extends TestCase
{
    public function testStandaloneConsoleAndHttpProfilesRenderTheSameCollector(): void
    {
        $directory = (getenv('DUCKDB_TEST_TMPDIR') ?: sys_get_temp_dir()).'/duckdb-profile-'.bin2hex(random_bytes(5));
        $fs = new Filesystem();
        $kernel = new \DuckDbDemoKernel($directory);
        try {
            $recorder = new QueryRecorder();
            $sources = new NamedSources(new ConnectionFactory($recorder, new SchemaInspector()), ['example' => ['uri' => $directory.'/var/example.duckdb']]);
            $demo = new \DuckDbDemoService($sources, $recorder, $directory);
            $application = (new SingleCommandApplication())->setAutoExit(false)->setCode($demo->run(...));
            self::assertSame(0, $application->run(new ArrayInput(['--prepare' => true]), new BufferedOutput()));
            $application = (new SingleCommandApplication())->setAutoExit(false)->setCode($demo->run(...));
            $output = new BufferedOutput();
            self::assertSame(0, $application->run(new ArrayInput(['--profile' => true, '--verbose' => true]), $output));
            $text = $output->fetch();
            self::assertSame(1, preg_match('~/_profiler/([a-f0-9]+)~', $text, $matches), $text);
            self::assertNotEmpty($matches[1]);
            $storage = new FileProfilerStorage('file:'.$directory.'/var/profiler');
            $saved = $storage->read($matches[1]);
            self::assertSame('command', $saved->getVirtualType());
            self::assertSame(4, $saved->getCollector('duckdb')->getQueryCount());
            self::assertSame(['query', 'prepare', 'execute', 'execute'], array_column($saved->getCollector('duckdb')->getQueries(), 'operation'));
            self::assertArrayHasKey('example', $saved->getCollector('duckdb')->getSchemas());
            $connection = $saved->getCollector('duckdb')->getConnections()[0];
            self::assertSame('local', $connection['transport']);
            self::assertSame($directory.'/var/example.duckdb', $connection['location']);
            self::assertSame('ready', $connection['status']);
            // No HTTP kernel was booted to create the console profile above.
            // A normal FrameworkBundle console application uses the same collector.
            $console = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
            $console->setAutoExit(false);
            $command = new \Symfony\Component\Console\Command\Command('test:query');
            $command->setCode(static function () use ($kernel): int {
                $kernel->getContainer()->get(\DuckDbDemoService::class)->rows();

                return 0;
            });
            $console->addCommand($command);
            self::assertSame(0, $console->run(new ArrayInput(['command' => 'test:query', '--profile' => true]), new BufferedOutput()));
            $profiles = $storage->find(null, 'test:query', 1, null, null, null);
            self::assertCount(1, $profiles);
            self::assertSame(4, $storage->read($profiles[0]['token'])->getCollector('duckdb')->getQueryCount());

            $kernel->shutdown();
            $kernel = new \DuckDbDemoKernel($directory);
            $request = Request::create('/');
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            self::assertStringContainsString('Blue pencil', $response->getContent());
            $kernel->terminate($request, $response);
            $httpToken = $response->headers->get('X-Debug-Token');
            self::assertNotEmpty($httpToken);
            self::assertSame(4, $storage->read($httpToken)->getCollector('duckdb')->getQueryCount());
            foreach ([$matches[1], $httpToken] as $token) {
                $request = Request::create('/_profiler/'.$token);
                $response = $kernel->handle($request);
                self::assertSame(200, $response->getStatusCode(), $response->getContent());
                self::assertStringContainsString('Schema snapshots', $response->getContent());
                self::assertStringContainsString('PDO connect (ms)', $response->getContent());
                self::assertStringContainsString('recent_products', $response->getContent());
                $kernel->terminate($request, $response);
            }
            $response = $kernel->handle(Request::create('/_wdt/'.$httpToken));
            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            self::assertStringContainsString('aria-label="DuckDB"', $response->getContent());
            self::assertStringContainsString('<span class="sf-toolbar-value">4</span>', $response->getContent());
        } finally {
            $kernel->shutdown();
            $fs->remove($directory);
        }
    }
}
