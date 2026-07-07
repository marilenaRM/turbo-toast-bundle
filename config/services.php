<?php

declare(strict_types=1);

use MarilenaRM\TurboToastBundle\EventSubscriber\ToastCookieSubscriber;
use MarilenaRM\TurboToastBundle\Toast\ToastRenderer;
use MarilenaRM\TurboToastBundle\Toast\ToastStack;
use MarilenaRM\TurboToastBundle\Twig\TurboToastExtension;
use MarilenaRM\TurboToastBundle\Twig\TurboToastRuntime;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set(ToastRenderer::class)
            ->args([
                service('twig'),
                service('request_stack'),
                param('marilena_rm_turbo_toast.stream_template'),
                param('marilena_rm_turbo_toast.target'),
                param('marilena_rm_turbo_toast.controller_name'),
                param('marilena_rm_turbo_toast.default_delay'),
            ])

        ->set(ToastStack::class)
            ->tag('kernel.reset', ['method' => 'reset'])

        ->set(ToastCookieSubscriber::class)
            ->args([
                service(ToastStack::class),
                param('marilena_rm_turbo_toast.cookie_name'),
                param('marilena_rm_turbo_toast.default_delay'),
            ])
            ->tag('kernel.event_subscriber')

        ->set(TurboToastExtension::class)
            ->tag('twig.extension')

        ->set(TurboToastRuntime::class)
            ->args([
                service('twig'),
                param('marilena_rm_turbo_toast.target'),
                param('marilena_rm_turbo_toast.controller_name'),
                param('marilena_rm_turbo_toast.cookie_name'),
            ])
            ->tag('twig.runtime');
};
