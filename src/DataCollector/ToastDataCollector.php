<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\DataCollector;

use MarilenaRM\TurboToastBundle\Debug\TraceableToastRenderer;
use MarilenaRM\TurboToastBundle\Debug\TraceableToastStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;

final class ToastDataCollector extends DataCollector
{
    public function __construct(
        private readonly TraceableToastRenderer $renderer,
        private readonly TraceableToastStack $stack,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'streams' => $this->renderer->getRendered(),
            'deferred' => [
                'pushed' => $this->stack->getPushed(),
                'transported' => $this->stack->getTransportedCount(),
                'discarded' => $this->stack->getDiscardedCount(),
            ],
        ];
    }

    public function getName(): string
    {
        return 'turbo_toast';
    }

    public function reset(): void
    {
        $this->data = [];
        $this->renderer->resetTraces();
        $this->stack->resetTraces();
    }

    /**
     * @return list<array{message: string, type: string, delay: int|null}>
     */
    public function getStreams(): array
    {
        return $this->data['streams'] ?? [];
    }

    /**
     * @return array{pushed: list<array{message: string, type: string, delay: int|null}>, transported: int, discarded: int}
     */
    public function getDeferred(): array
    {
        return $this->data['deferred'] ?? ['pushed' => [], 'transported' => 0, 'discarded' => 0];
    }

    public function getTotal(): int
    {
        return \count($this->getStreams()) + \count($this->getDeferred()['pushed']);
    }
}
