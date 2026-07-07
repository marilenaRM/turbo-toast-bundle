<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests\Toast;

use PHPUnit\Framework\TestCase;
use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastStack;
use Symfony\Contracts\Service\ResetInterface;

final class ToastStackTest extends TestCase
{
    public function testDrainReturnsAndEmpties(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('One'), new Toast('Two'));

        self::assertCount(2, $stack->drain());
        self::assertSame([], $stack->drain());
    }

    public function testResetEmptiesTheStack(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Leaked?'));

        self::assertInstanceOf(ResetInterface::class, $stack);

        $stack->reset();

        self::assertSame([], $stack->drain(), 'reset() must drop undrained toasts so worker runtimes never leak them across requests');
    }
}
