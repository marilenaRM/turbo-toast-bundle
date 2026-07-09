<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Debug;

use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastStackInterface;

/**
 * Records the cookie-deferred toast lifecycle (pushed, transported,
 * discarded) for the profiler panel.
 *
 * reset() keeps its domain meaning (discard) and does NOT clear the traces:
 * the subscriber may discard on a 5xx before the profiler collects. Traces
 * are cleared by the data collector's own reset().
 */
final class TraceableToastStack implements ToastStackInterface
{
    /**
     * @var list<array{message: string, type: string, delay: int|null}>
     */
    private array $pushed = [];

    private int $transported = 0;
    private int $discarded = 0;

    public function __construct(
        private readonly ToastStackInterface $inner,
    ) {
    }

    public function push(Toast ...$toasts): void
    {
        foreach ($toasts as $toast) {
            $this->pushed[] = [
                'message' => $toast->message,
                'type' => $toast->type,
                'delay' => $toast->delay,
            ];
        }

        $this->inner->push(...$toasts);
    }

    public function drain(): array
    {
        $drained = $this->inner->drain();
        $this->transported += \count($drained);

        return $drained;
    }

    public function reset(): void
    {
        $this->discarded += \count($this->inner);
        $this->inner->reset();
    }

    public function count(): int
    {
        return \count($this->inner);
    }

    /**
     * @return list<array{message: string, type: string, delay: int|null}>
     */
    public function getPushed(): array
    {
        return $this->pushed;
    }

    public function getTransportedCount(): int
    {
        return $this->transported;
    }

    public function getDiscardedCount(): int
    {
        return $this->discarded;
    }

    public function resetTraces(): void
    {
        $this->pushed = [];
        $this->transported = 0;
        $this->discarded = 0;
    }
}
