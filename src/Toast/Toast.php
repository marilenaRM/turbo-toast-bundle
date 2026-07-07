<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Toast;

final readonly class Toast
{
    /**
     * @param string   $type  Free-form variant, rendered as the CSS modifier `toast--{type}` (e.g. success, error, warning, info).
     * @param int|null $delay Auto-dismiss delay in ms; null falls back to the bundle default.
     */
    public function __construct(
        public string $message,
        public string $type = 'success',
        public ?int $delay = null,
    ) {
    }
}
