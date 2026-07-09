<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests\Debug;

use MarilenaRM\TurboToastBundle\Debug\TraceableToastRenderer;
use MarilenaRM\TurboToastBundle\Tests\TwigTestCase;
use MarilenaRM\TurboToastBundle\Toast\Toast;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class TraceableToastRendererTest extends TwigTestCase
{
    public function testItRecordsRenderedToastsAndDelegates(): void
    {
        $renderer = new TraceableToastRenderer($this->createRenderer());

        $response = $renderer->render(new Toast('Saved', 'success', 2000), new Toast('Oops', 'error'));

        self::assertStringContainsString('Saved', (string) $response->getContent());
        self::assertSame([
            ['message' => 'Saved', 'type' => 'success', 'delay' => 2000],
            ['message' => 'Oops', 'type' => 'error', 'delay' => null],
        ], $renderer->getRendered());
    }

    public function testARefusedRenderIsNotRecorded(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request()); // no turbo-stream Accept header

        $renderer = new TraceableToastRenderer($this->createRenderer(requestStack: $stack));

        try {
            $renderer->render(new Toast('Lost'));
            self::fail('A LogicException should have been thrown.');
        } catch (\LogicException) {
        }

        self::assertSame([], $renderer->getRendered());
    }

    public function testResetTracesClearsTheRecords(): void
    {
        $renderer = new TraceableToastRenderer($this->createRenderer());
        $renderer->render(new Toast('Saved'));

        $renderer->resetTraces();

        self::assertSame([], $renderer->getRendered());
    }
}
