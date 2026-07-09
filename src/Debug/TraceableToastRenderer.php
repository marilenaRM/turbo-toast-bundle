<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Debug;

use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastRendererInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the toasts rendered as Turbo Streams for the profiler panel.
 */
final class TraceableToastRenderer implements ToastRendererInterface
{
    /**
     * @var list<array{message: string, type: string, delay: int|null}>
     */
    private array $rendered = [];

    public function __construct(
        private readonly ToastRendererInterface $inner,
    ) {
    }

    public function render(Toast ...$toasts): Response
    {
        $response = $this->inner->render(...$toasts);

        // Recorded after the inner call: a refused render (LogicException)
        // must not show up as a rendered toast.
        foreach ($toasts as $toast) {
            $this->rendered[] = [
                'message' => $toast->message,
                'type' => $toast->type,
                'delay' => $toast->delay,
            ];
        }

        return $response;
    }

    /**
     * @return list<array{message: string, type: string, delay: int|null}>
     */
    public function getRendered(): array
    {
        return $this->rendered;
    }

    public function resetTraces(): void
    {
        $this->rendered = [];
    }
}
