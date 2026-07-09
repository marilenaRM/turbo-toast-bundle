<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Toast;

use Symfony\Component\HttpFoundation\Response;

interface ToastRendererInterface
{
    /**
     * Builds a turbo-stream response appending the given toasts to the DOM.
     *
     * @throws \LogicException when the current request does not accept Turbo Streams
     */
    public function render(Toast ...$toasts): Response;
}
