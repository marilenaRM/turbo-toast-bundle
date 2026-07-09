<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests\Debug;

use PHPUnit\Framework\TestCase;
use MarilenaRM\TurboToastBundle\Debug\TraceableToastStack;
use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastStack;

final class TraceableToastStackTest extends TestCase
{
    public function testItRecordsPushesAndDelegates(): void
    {
        $inner = new ToastStack();
        $stack = new TraceableToastStack($inner);

        $stack->push(new Toast('Hello', 'info', 3000));

        self::assertSame(
            [['message' => 'Hello', 'type' => 'info', 'delay' => 3000]],
            $stack->getPushed(),
        );
        self::assertCount(1, $inner);
    }

    public function testDrainCountsTransportedToasts(): void
    {
        $stack = new TraceableToastStack(new ToastStack());
        $stack->push(new Toast('One'), new Toast('Two'));

        $drained = $stack->drain();

        self::assertCount(2, $drained);
        self::assertSame(2, $stack->getTransportedCount());
        self::assertCount(0, $stack);
    }

    public function testResetCountsDiscardedToastsAndKeepsTraces(): void
    {
        $stack = new TraceableToastStack(new ToastStack());
        $stack->push(new Toast('Doomed'));

        $stack->reset();

        self::assertSame(1, $stack->getDiscardedCount());
        self::assertCount(0, $stack);
        self::assertCount(1, $stack->getPushed(), 'reset() must keep the traces for the collector');
    }

    public function testResetTracesClearsEverything(): void
    {
        $stack = new TraceableToastStack(new ToastStack());
        $stack->push(new Toast('One'));
        $stack->drain();
        $stack->push(new Toast('Two'));
        $stack->reset();

        $stack->resetTraces();

        self::assertSame([], $stack->getPushed());
        self::assertSame(0, $stack->getTransportedCount());
        self::assertSame(0, $stack->getDiscardedCount());
    }
}
