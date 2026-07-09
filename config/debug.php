<?php

declare(strict_types=1);

use MarilenaRM\TurboToastBundle\DataCollector\ToastDataCollector;
use MarilenaRM\TurboToastBundle\Debug\TraceableToastRenderer;
use MarilenaRM\TurboToastBundle\Debug\TraceableToastStack;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('marilena_rm_turbo_toast.debug.renderer', TraceableToastRenderer::class)
        ->decorate('marilena_rm_turbo_toast.renderer')
        ->args([
            service('marilena_rm_turbo_toast.debug.renderer.inner'),
        ]);

    $services->set('marilena_rm_turbo_toast.debug.stack', TraceableToastStack::class)
        ->decorate('marilena_rm_turbo_toast.stack')
        ->args([
            service('marilena_rm_turbo_toast.debug.stack.inner'),
        ]);

    $services->set('marilena_rm_turbo_toast.data_collector', ToastDataCollector::class)
        ->args([
            service('marilena_rm_turbo_toast.debug.renderer'),
            service('marilena_rm_turbo_toast.debug.stack'),
        ])
        ->tag('data_collector', [
            'id' => 'turbo_toast',
            'template' => '@MarilenaRMTurboToast/data_collector/toast.html.twig',
        ]);
};
