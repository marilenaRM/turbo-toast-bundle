<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests\Controller;

use MarilenaRM\TurboToastBundle\Controller\TurboToastTrait;
use MarilenaRM\TurboToastBundle\Tests\TwigTestCase;
use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastStack;
use Symfony\Component\HttpFoundation\Response;

final class TurboToastTraitTest extends TwigTestCase
{
    private ToastStack $stack;

    public function testToastDelegatesToTheRenderer(): void
    {
        $controller = $this->createController();

        $response = $controller->createToast('Saved', 'success');

        self::assertInstanceOf(Response::class, $response);
        self::assertStringContainsString('Saved', (string) $response->getContent());
        self::assertStringContainsString('toast--success', (string) $response->getContent());
    }

    public function testToastsDelegatesToTheRenderer(): void
    {
        $controller = $this->createController();

        $response = $controller->createToasts(
            new Toast('First'),
            new Toast('Second', 'info'),
        );

        $body = (string) $response->getContent();
        self::assertSame(2, substr_count($body, '<turbo-stream'));
        self::assertStringContainsString('First', $body);
        self::assertStringContainsString('Second', $body);
    }

    public function testDeferToastPushesToTheStack(): void
    {
        $controller = $this->createController();

        $controller->queueToast('See you on the next page', 'info', 8000);

        $toasts = $this->stack->drain();
        self::assertCount(1, $toasts);
        self::assertSame('See you on the next page', $toasts[0]->message);
        self::assertSame('info', $toasts[0]->type);
        self::assertSame(8000, $toasts[0]->delay);
    }

    private function createController(): object
    {
        $controller = new class {
            use TurboToastTrait;

            public function createToast(string $message, string $type): Response
            {
                return $this->toast($message, $type);
            }

            public function createToasts(Toast ...$toasts): Response
            {
                return $this->toasts(...$toasts);
            }

            public function queueToast(string $message, string $type, ?int $delay): void
            {
                $this->deferToast($message, $type, $delay);
            }
        };

        $this->stack = new ToastStack();
        $controller->setTurboToastRenderer($this->createRenderer());
        $controller->setTurboToastStack($this->stack);

        return $controller;
    }
}
