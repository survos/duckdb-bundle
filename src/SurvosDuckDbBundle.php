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
        $container->services()->set(ConnectionFactory::class);
        $container->services()->set(NamedSources::class)->args([service(ConnectionFactory::class), $config['sources']]);
    }
}
