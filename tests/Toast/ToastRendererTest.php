<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests\Toast;

use MarilenaRM\TurboToastBundle\Tests\TwigTestCase;
use MarilenaRM\TurboToastBundle\Toast\Toast;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\Turbo\TurboBundle;

final class ToastRendererTest extends TwigTestCase
{
    public function testItRendersATurboStreamResponse(): void
    {
        $response = $this->createRenderer()->render(new Toast('Saved'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            TurboBundle::STREAM_MEDIA_TYPE,
            $response->headers->get('Content-Type'),
        );

        $body = (string) $response->getContent();
        self::assertStringContainsString('<turbo-stream action="append" target="toasts">', $body);
        self::assertStringContainsString('Saved', $body);
        self::assertStringContainsString('data-controller="marilenarm--turbo-toast--toast"', $body);
        self::assertStringContainsString('toast--success', $body);
        self::assertStringContainsString('role="status"', $body);
    }

    public function testTypeAndDelayAreRendered(): void
    {
        $body = (string) $this->createRenderer()
            ->render(new Toast('Boom', 'error', 8000))
            ->getContent();

        self::assertStringContainsString('toast--error', $body);
        self::assertStringContainsString('role="alert"', $body);
        self::assertStringContainsString('data-marilenarm--turbo-toast--toast-delay-value="8000"', $body);
        self::assertStringContainsString('click->marilenarm--turbo-toast--toast#dismiss', $body);
    }

    public function testNullDelayFallsBackToTheConfiguredDefault(): void
    {
        $body = (string) $this->createRenderer(defaultDelay: 3000)
            ->render(new Toast('Hello'))
            ->getContent();

        self::assertStringContainsString('data-marilenarm--turbo-toast--toast-delay-value="3000"', $body);
    }

    public function testCustomTargetAndControllerName(): void
    {
        $body = (string) $this->createRenderer(target: 'flashes', controllerName: 'notice')
            ->render(new Toast('Hi'))
            ->getContent();

        self::assertStringContainsString('target="flashes"', $body);
        self::assertStringContainsString('data-controller="notice"', $body);
        self::assertStringContainsString('data-notice-delay-value=', $body);
        self::assertStringContainsString('notice#dismiss', $body);
    }

    public function testMultipleToastsProduceMultipleStreams(): void
    {
        $body = (string) $this->createRenderer()
            ->render(new Toast('First'), new Toast('Second', 'info'))
            ->getContent();

        self::assertSame(2, substr_count($body, '<turbo-stream'));
        self::assertStringContainsString('First', $body);
        self::assertStringContainsString('Second', $body);
    }

    public function testMessagesAreEscaped(): void
    {
        $body = (string) $this->createRenderer()
            ->render(new Toast('<script>alert(1)</script>'))
            ->getContent();

        self::assertStringNotContainsString('<script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testItThrowsWhenTheRequestDoesNotAcceptTurboStreams(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request()); // no turbo-stream Accept header

        $renderer = $this->createRenderer(requestStack: $stack);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/deferToast\(\)/');

        $renderer->render(new Toast('Lost'));
    }

    public function testItRendersWithoutACurrentRequest(): void
    {
        $renderer = $this->createRenderer(requestStack: new RequestStack());

        $body = (string) $renderer->render(new Toast('CLI-ish'))->getContent();

        self::assertStringContainsString('CLI-ish', $body);
    }
}
