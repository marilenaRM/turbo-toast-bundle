<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Toast;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/**
 * Builds a turbo-stream {@see Response} that appends toasts to the DOM,
 * without ever touching the session or the flash bag.
 */
final readonly class ToastRenderer
{
    public function __construct(
        private Environment $twig,
        private RequestStack $requestStack,
        private ToastConfig $config,
    ) {
    }

    public function render(Toast ...$toasts): Response
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request && !str_contains($request->headers->get('Accept', ''), TurboBundle::STREAM_MEDIA_TYPE)) {
            throw new \LogicException(\sprintf('The current request does not accept Turbo Streams ("%s" not in the Accept header). A toast rendered here would reach the browser as raw markup. Use deferToast() before returning a redirect instead.', TurboBundle::STREAM_MEDIA_TYPE));
        }

        $html = $this->twig->render($this->config->streamTemplate, [
            'toasts' => $toasts,
            'target' => $this->config->target,
            'controller' => $this->config->controllerName,
            'default_delay' => $this->config->defaultDelay,
        ]);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => TurboBundle::STREAM_MEDIA_TYPE,
        ]);
    }
}
