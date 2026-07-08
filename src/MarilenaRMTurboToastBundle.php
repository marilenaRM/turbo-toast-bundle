<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class MarilenaRMTurboToastBundle extends AbstractBundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('target')
                    ->info('DOM id of the container the toasts are appended to.')
                    ->defaultValue('toasts')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('controller_name')
                    ->info('Stimulus controller name, in stimulus_controller() notation (auto-registered UX packages are namespaced). Override if the app renames the controller in controllers.json.')
                    ->defaultValue('marilenarm/turbo-toast/toast')
                    ->cannotBeEmpty()
                ->end()
                ->integerNode('default_delay')
                    ->info('Auto-dismiss delay in milliseconds (0 disables it).')
                    ->defaultValue(5000)
                    ->min(0)
                ->end()
                ->scalarNode('stream_template')
                    ->info('Twig template rendering the turbo-stream payload.')
                    ->defaultValue('@MarilenaRMTurboToast/toast.stream.html.twig')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('cookie_name')
                    ->info('Name of the short-lived cookie transporting deferred toasts across redirects.')
                    ->defaultValue('turbo_toast')
                    ->cannotBeEmpty()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->parameters()
            ->set('marilena_rm_turbo_toast.target', $config['target'])
            ->set('marilena_rm_turbo_toast.controller_name', $config['controller_name'])
            ->set('marilena_rm_turbo_toast.default_delay', $config['default_delay'])
            ->set('marilena_rm_turbo_toast.stream_template', $config['stream_template'])
            ->set('marilena_rm_turbo_toast.cookie_name', $config['cookie_name']);

        $configurator->import('../config/services.php');
    }
}
