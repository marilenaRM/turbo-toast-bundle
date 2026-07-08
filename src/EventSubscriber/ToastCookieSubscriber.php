<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\EventSubscriber;

use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastStack;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Serializes deferred toasts into a short-lived, non-HttpOnly cookie so they
 * survive a classic full-page redirect without touching the session.
 *
 * The toast-container Stimulus controller reads and clears the cookie on the
 * next page load.
 */
final class ToastCookieSubscriber implements EventSubscriberInterface
{
    /**
     * Conservative budget for the url-encoded cookie value (browser limit is ~4096
     * bytes for the whole Set-Cookie line). Trailing toasts are dropped beyond it.
     */
    private const MAX_COOKIE_SIZE = 3800;

    public function __construct(
        private readonly ToastStack $stack,
        private readonly string $cookieName,
        private readonly int $defaultDelay,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ResponseEvent::class => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        // A request that ended in a server error must not promise success on
        // the next page: discard the queued toasts instead of transporting them.
        if ($response->isServerError()) {
            $this->stack->reset();

            return;
        }

        $toasts = $this->stack->drain();
        if ([] === $toasts) {
            return;
        }

        $payload = array_map(fn (Toast $toast): array => [
            'message' => $toast->message,
            'type' => $toast->type,
            'delay' => $toast->delay ?? $this->defaultDelay,
        ], $toasts);

        do {
            $value = $this->encode($payload);
            if (\strlen(rawurlencode($value)) <= self::MAX_COOKIE_SIZE) {
                break;
            }
            array_pop($payload);
        } while ([] !== $payload);

        if ([] === $payload) {
            return;
        }

        $response->headers->setCookie(
            Cookie::create($this->cookieName)
                ->withValue($value)
                ->withPath('/')
                ->withSecure($event->getRequest()->isSecure())
                ->withSameSite(Cookie::SAMESITE_LAX)
                ->withHttpOnly(false), // the Stimulus controller must read it
        );

        // A Set-Cookie carrying a user-specific toast must never enter a shared
        // cache (same protection as AbstractSessionListener for the session).
        $response->setPrivate();
        $response->headers->removeCacheControlDirective('s-maxage');
    }

    /**
     * @param list<array{message: string, type: string, delay: int}> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }
}
