<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Controller;

use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastRendererInterface;
use MarilenaRM\TurboToastBundle\Toast\ToastStackInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Ergonomic helpers for controllers:
 *  - toast()/toasts() return an immediate turbo-stream response;
 *  - deferToast() queues a toast that survives a full-page redirect via cookie.
 *
 * Requires the controller to be autowired (default in Symfony apps) so the
 * {@see Required} setters are honored.
 */
trait TurboToastTrait
{
    private ToastRendererInterface $turboToastRenderer;
    private ToastStackInterface $turboToastStack;

    #[Required]
    public function setTurboToastRenderer(ToastRendererInterface $turboToastRenderer): void
    {
        $this->turboToastRenderer = $turboToastRenderer;
    }

    #[Required]
    public function setTurboToastStack(ToastStackInterface $turboToastStack): void
    {
        $this->turboToastStack = $turboToastStack;
    }

    protected function toast(string $message, string $type = 'success', ?int $delay = null): Response
    {
        return $this->turboToastRenderer->render(new Toast($message, $type, $delay));
    }

    protected function toasts(Toast ...$toasts): Response
    {
        return $this->turboToastRenderer->render(...$toasts);
    }

    /**
     * Queues a toast for the next page load. Use before returning a classic
     * RedirectResponse (non-Turbo flow): the toast is transported by a
     * short-lived cookie instead of the session.
     */
    protected function deferToast(string $message, string $type = 'success', ?int $delay = null): void
    {
        $this->turboToastStack->push(new Toast($message, $type, $delay));
    }
}
