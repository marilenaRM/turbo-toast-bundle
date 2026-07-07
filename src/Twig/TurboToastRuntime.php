<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Twig;

use Twig\Environment;

/**
 * Renders the toast container div with the Stimulus values injected from the
 * bundle configuration, so PHP config and JS defaults can never drift apart.
 */
final readonly class TurboToastRuntime
{
    public function __construct(
        private Environment $twig,
        private string $target,
        private string $controllerName,
        private string $cookieName,
    ) {
    }

    public function renderContainer(): string
    {
        return $this->twig->render('@MarilenaRMTurboToast/container.html.twig', [
            'target' => $this->target,
            'cookie_name' => $this->cookieName,
            'toast_controller' => $this->normalizeControllerName($this->controllerName),
        ]);
    }

    /**
     * Same normalization as StimulusBundle: the JS side needs the HTML
     * identifier ("marilenarm--turbo-toast--toast"), not the helper notation
     * ("marilenarm/turbo-toast/toast").
     */
    private function normalizeControllerName(string $controllerName): string
    {
        return preg_replace('/^@/', '', str_replace('_', '-', str_replace('/', '--', $controllerName)));
    }
}
