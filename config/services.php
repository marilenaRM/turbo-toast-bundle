<?php

declare(strict_types=1);

use MarilenaRM\TurboToastBundle\EventSubscriber\ToastCookieSubscriber;
use MarilenaRM\TurboToastBundle\Toast\ToastConfig;
use MarilenaRM\TurboToastBundle\Toast\ToastRenderer;
use MarilenaRM\TurboToastBundle\Toast\ToastRendererInterface;
use MarilenaRM\TurboToastBundle\Toast\ToastStack;
use MarilenaRM\TurboToastBundle\Toast\ToastStackInterface;
use MarilenaRM\TurboToastBundle\Twig\TurboToastExtension;
use MarilenaRM\TurboToastBundle\Twig\TurboToastRuntime;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('marilena_rm_turbo_toast.config', ToastConfig::class)
        ->args([
            param('marilena_rm_turbo_toast.target'),
            param('marilena_rm_turbo_toast.controller_name'),
            param('marilena_rm_turbo_toast.cookie_name'),
            param('marilena_rm_turbo_toast.default_delay'),
            param('marilena_rm_turbo_toast.stream_template'),
        ]);
    $services->alias(ToastConfig::class, 'marilena_rm_turbo_toast.config');

    $services->set('marilena_rm_turbo_toast.stack', ToastStack::class)
        ->tag('kernel.reset', ['method' => 'reset']);
    $services->alias(ToastStackInterface::class, 'marilena_rm_turbo_toast.stack');

    $services->set('marilena_rm_turbo_toast.renderer', ToastRenderer::class)
        ->args([
            service('twig'),
            service('request_stack'),
            service('marilena_rm_turbo_toast.config'),
        ]);
    $services->alias(ToastRendererInterface::class, 'marilena_rm_turbo_toast.renderer');

    $services->set('marilena_rm_turbo_toast.cookie_subscriber', ToastCookieSubscriber::class)
        ->args([
            service('marilena_rm_turbo_toast.stack'),
            service('marilena_rm_turbo_toast.config'),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_subscriber')
        ->tag('monolog.logger', ['channel' => 'turbo_toast']);

    $services->set('marilena_rm_turbo_toast.twig_extension', TurboToastExtension::class)
        ->tag('twig.extension');

    $services->set('marilena_rm_turbo_toast.twig_runtime', TurboToastRuntime::class)
        ->args([
            service('twig'),
            service('marilena_rm_turbo_toast.config'),
        ])
        ->tag('twig.runtime');
};
