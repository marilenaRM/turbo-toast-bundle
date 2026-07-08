<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Twig;

use MarilenaRM\TurboToastBundle\Toast\ToastConfig;
use Twig\Environment;

/**
 * Renders the toast container div with the Stimulus values injected from the
 * bundle configuration, so PHP config and JS defaults can never drift apart.
 */
final readonly class TurboToastRuntime
{
    public function __construct(
        private Environment $twig,
        private ToastConfig $config,
    ) {
    }

    public function renderContainer(): string
    {
        return $this->twig->render('@MarilenaRMTurboToast/container.html.twig', [
            'target' => $this->config->target,
            'cookie_name' => $this->config->cookieName,
            'toast_controller' => $this->normalizeControllerName($this->config->controllerName),
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
