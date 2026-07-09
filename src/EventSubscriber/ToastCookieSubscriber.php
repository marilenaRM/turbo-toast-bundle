<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\EventSubscriber;

use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastConfig;
use MarilenaRM\TurboToastBundle\Toast\ToastStackInterface;
use Psr\Log\LoggerInterface;
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
        private readonly ToastStackInterface $stack,
        private readonly ToastConfig $config,
        private readonly ?LoggerInterface $logger = null,
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
            if (0 < $count = \count($this->stack)) {
                $this->logger?->warning('Discarded {count} queued toast(s): the response is a server error.', ['count' => $count]);
            }
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
            'delay' => $toast->delay ?? $this->config->defaultDelay,
        ], $toasts);

        $dropped = 0;
        do {
            $value = $this->encode($payload);
            if (\strlen(rawurlencode($value)) <= self::MAX_COOKIE_SIZE) {
                break;
            }
            array_pop($payload);
            ++$dropped;
        } while ([] !== $payload);

        if (0 < $dropped) {
            $this->logger?->warning('Dropped {dropped} of {total} queued toast(s) exceeding the cookie size budget.', [
                'dropped' => $dropped,
                'total' => \count($toasts),
            ]);
        }

        if ([] === $payload) {
            return;
        }

        $response->headers->setCookie(
            Cookie::create($this->config->cookieName)
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

        $this->logger?->debug('Deferred {count} toast(s) into the "{cookie}" cookie.', [
            'count' => \count($payload),
            'cookie' => $this->config->cookieName,
        ]);
    }

    /**
     * @param list<array{message: string, type: string, delay: int}> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }
}
