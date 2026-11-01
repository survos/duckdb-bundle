<?php

declare(strict_types=1);

namespace Survos\DuckDbBundle;

use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosDuckDbBundle extends AbstractSurvosBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->booleanNode('profiling')->defaultNull()->end()
            ->booleanNode('collect_schema')->defaultTrue()->end()
            ->integerNode('max_queries')->min(1)->defaultValue(500)->end()
            ->arrayNode('sources')->useAttributeAsKey('name')->normalizeKeys(false)->arrayPrototype()->children()
                ->scalarNode('uri')->isRequired()->cannotBeEmpty()->end()
                ->arrayNode('s3')->children()
                    ->scalarNode('endpoint')->defaultValue('https://s3.amazonaws.com')->end()
                    ->scalarNode('region')->defaultValue('us-east-1')->end()
                    ->enumNode('url_style')->values(['path', 'vhost'])->defaultValue('vhost')->end()
                    ->scalarNode('access_key')->defaultValue('')->end()
                    ->scalarNode('secret_key')->defaultValue('')->end()
                    ->scalarNode('session_token')->defaultNull()->end()
                ->end()->end()
            ->end()->end()->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $services = $container->services();
        $services->set(SchemaInspector::class);
        $factory = $services->set(ConnectionFactory::class);
        if ($config['profiling'] ?? $builder->getParameter('kernel.debug')) {
            $services->set(Debug\QueryRecorder::class)->arg('$maxQueries', $config['max_queries'])->tag('kernel.reset', ['method' => 'reset']);
            $services->set(Debug\DuckDbDataCollector::class)->args([service(Debug\QueryRecorder::class)])
                ->tag('data_collector', ['id' => 'duckdb', 'template' => '@SurvosDuckDb/Collector/duckdb.html.twig']);
            $factory->arg('$recorder', service(Debug\QueryRecorder::class));
            if (interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
                $services->set(Debug\Doctrine\ProfilingMiddleware::class)
                    ->args([service(Debug\QueryRecorder::class), $config['collect_schema'] ? service(SchemaInspector::class) : null])
                    ->tag('doctrine.middleware');
            }

            if ($config['collect_schema']) {
                $factory->arg('$inspector', service(SchemaInspector::class));
            }
        }
        $container->services()->set(NamedSources::class)->args([service(ConnectionFactory::class), $config['sources']]);
    }
}
