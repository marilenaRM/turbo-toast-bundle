<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests;

use PHPUnit\Framework\TestCase;
use MarilenaRM\TurboToastBundle\Toast\ToastRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\StimulusBundle\Helper\StimulusHelper;
use Symfony\UX\StimulusBundle\Twig\StimulusTwigExtension;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

abstract class TwigTestCase extends TestCase
{
    protected function createRenderer(
        string $target = 'toasts',
        string $controllerName = 'marilenarm/turbo-toast/toast',
        int $defaultDelay = 5000,
        ?RequestStack $requestStack = null,
    ): ToastRenderer {
        return new ToastRenderer(
            self::createTwig(),
            $requestStack ?? self::createTurboRequestStack(),
            '@MarilenaRMTurboToast/toast.stream.html.twig',
            $target,
            $controllerName,
            $defaultDelay,
        );
    }

    protected static function createTwig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../templates', 'MarilenaRMTurboToast');

        $twig = new Environment($loader);
        $twig->addExtension(new StimulusTwigExtension(new StimulusHelper($twig)));

        return $twig;
    }

    protected static function createTurboRequestStack(): RequestStack
    {
        $request = new Request();
        $request->headers->set('Accept', TurboBundle::STREAM_MEDIA_TYPE . ', text/html');

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
