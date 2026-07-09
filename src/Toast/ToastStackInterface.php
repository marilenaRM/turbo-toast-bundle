<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Toast;

use Symfony\Contracts\Service\ResetInterface;

interface ToastStackInterface extends ResetInterface, \Countable
{
    public function push(Toast ...$toasts): void;

    /**
     * Returns the accumulated toasts and empties the stack.
     *
     * @return list<Toast>
     */
    public function drain(): array;
}
