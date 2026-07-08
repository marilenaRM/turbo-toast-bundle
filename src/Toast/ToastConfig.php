<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Toast;

/**
 * Resolved bundle configuration, shared by every service that needs it.
 */
final readonly class ToastConfig
{
    public function __construct(
        public string $target,
        public string $controllerName,
        public string $cookieName,
        public int $defaultDelay,
        public string $streamTemplate,
    ) {
    }
}
