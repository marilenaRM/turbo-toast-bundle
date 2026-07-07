<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class TurboToastExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('turbo_toast_container', [TurboToastRuntime::class, 'renderContainer'], ['is_safe' => ['html']]),
        ];
    }
}
