<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests\Twig;

use MarilenaRM\TurboToastBundle\Tests\TwigTestCase;
use MarilenaRM\TurboToastBundle\Twig\TurboToastRuntime;

final class TurboToastRuntimeTest extends TwigTestCase
{
    public function testItRendersTheContainerFromTheConfiguration(): void
    {
        $html = $this->createRuntime()->renderContainer();

        self::assertStringContainsString('id="toasts"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('data-turbo-permanent', $html);
        self::assertStringContainsString('data-controller="marilenarm--turbo-toast--toast-container"', $html);
        self::assertStringContainsString(
            'data-marilenarm--turbo-toast--toast-container-cookie-name-value="turbo_toast"',
            $html,
        );
        self::assertStringContainsString(
            'data-marilenarm--turbo-toast--toast-container-toast-controller-value="marilenarm--turbo-toast--toast"',
            $html,
        );
    }

    public function testConfigurationChangesPropagateToTheMarkup(): void
    {
        $html = $this->createRuntime(
            target: 'flashes',
            controllerName: 'notice',
            cookieName: 'my_flashes',
        )->renderContainer();

        self::assertStringContainsString('id="flashes"', $html);
        self::assertStringContainsString('cookie-name-value="my_flashes"', $html);
        self::assertStringContainsString('toast-controller-value="notice"', $html);
    }

    private function createRuntime(
        string $target = 'toasts',
        string $controllerName = 'marilenarm/turbo-toast/toast',
        string $cookieName = 'turbo_toast',
    ): TurboToastRuntime {
        return new TurboToastRuntime(
            self::createTwig(),
            self::createConfig(target: $target, controllerName: $controllerName, cookieName: $cookieName),
        );
    }
}
