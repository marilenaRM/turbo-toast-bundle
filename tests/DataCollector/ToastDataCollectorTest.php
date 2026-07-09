<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests\DataCollector;

use MarilenaRM\TurboToastBundle\DataCollector\ToastDataCollector;
use MarilenaRM\TurboToastBundle\Debug\TraceableToastRenderer;
use MarilenaRM\TurboToastBundle\Debug\TraceableToastStack;
use MarilenaRM\TurboToastBundle\Tests\TwigTestCase;
use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ToastDataCollectorTest extends TwigTestCase
{
    public function testItCollectsBothTransports(): void
    {
        [$collector, $renderer, $stack] = $this->createCollector();

        $renderer->render(new Toast('Streamed', 'success', 1000));
        $stack->push(new Toast('Deferred', 'info'));
        $stack->drain();

        $collector->collect(new Request(), new Response());

        self::assertSame('turbo_toast', $collector->getName());
        self::assertCount(1, $collector->getStreams());
        self::assertSame('Streamed', $collector->getStreams()[0]['message']);

        $deferred = $collector->getDeferred();
        self::assertCount(1, $deferred['pushed']);
        self::assertSame('Deferred', $deferred['pushed'][0]['message']);
        self::assertSame(1, $deferred['transported']);
        self::assertSame(0, $deferred['discarded']);

        self::assertSame(2, $collector->getTotal());
    }

    public function testDiscardedToastsAreStillVisibleToTheCollector(): void
    {
        [$collector, , $stack] = $this->createCollector();

        $stack->push(new Toast('Doomed'));
        $stack->reset(); // 5xx discard happens before the profiler collects

        $collector->collect(new Request(), new Response());

        $deferred = $collector->getDeferred();
        self::assertCount(1, $deferred['pushed']);
        self::assertSame(1, $deferred['discarded']);
    }

    public function testResetClearsTheCollectorAndTheTraces(): void
    {
        [$collector, $renderer, $stack] = $this->createCollector();

        $renderer->render(new Toast('Streamed'));
        $stack->push(new Toast('Deferred'));
        $collector->collect(new Request(), new Response());

        $collector->reset();

        self::assertSame(0, $collector->getTotal());
        self::assertSame([], $renderer->getRendered());
        self::assertSame([], $stack->getPushed());
    }

    /**
     * @return array{ToastDataCollector, TraceableToastRenderer, TraceableToastStack}
     */
    private function createCollector(): array
    {
        $renderer = new TraceableToastRenderer($this->createRenderer());
        $stack = new TraceableToastStack(new ToastStack());

        return [new ToastDataCollector($renderer, $stack), $renderer, $stack];
    }
}
