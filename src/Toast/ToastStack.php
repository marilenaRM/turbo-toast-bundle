<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Toast;

/**
 * Request-scoped accumulator for toasts that must survive a classic
 * full-page redirect (POST -> GET). Drained by the cookie subscriber
 * at kernel.response time.
 *
 * Resettable so that long-running runtimes (FrankenPHP worker mode,
 * RoadRunner, Swoole) never leak an undrained stack into the next
 * request, which could belong to another user.
 */
final class ToastStack implements ToastStackInterface
{
    /**
     * @var list<Toast>
     */
    private array $toasts = [];

    public function push(Toast ...$toasts): void
    {
        foreach ($toasts as $toast) {
            $this->toasts[] = $toast;
        }
    }

    /**
     * Returns the accumulated toasts and empties the stack.
     *
     * @return list<Toast>
     */
    public function drain(): array
    {
        $toasts = $this->toasts;
        $this->toasts = [];

        return $toasts;
    }

    public function reset(): void
    {
        $this->toasts = [];
    }

    public function count(): int
    {
        return \count($this->toasts);
    }
}
