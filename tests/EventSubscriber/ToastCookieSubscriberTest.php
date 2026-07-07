<?php

declare(strict_types=1);

namespace MarilenaRM\TurboToastBundle\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use MarilenaRM\TurboToastBundle\EventSubscriber\ToastCookieSubscriber;
use MarilenaRM\TurboToastBundle\Toast\Toast;
use MarilenaRM\TurboToastBundle\Toast\ToastStack;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ToastCookieSubscriberTest extends TestCase
{
    public function testItSetsTheCookieOnRedirect(): void
    {
        $stack = new ToastStack();
        // Non-ASCII on purpose: locks the JSON_UNESCAPED_UNICODE cookie encoding.
        $stack->push(new Toast('Café menu updated ✓', 'success'));

        $response = new RedirectResponse('/dashboard');
        $this->dispatch($stack, $response);

        $cookie = $this->findCookie($response, 'turbo_toast');
        self::assertNotNull($cookie);
        self::assertFalse($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());

        $payload = json_decode((string) $cookie->getValue(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(
            [['message' => 'Café menu updated ✓', 'type' => 'success', 'delay' => 5000]],
            $payload,
        );
    }

    public function testNullDelayIsResolvedToTheConfiguredDefault(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Hello', 'info', null), new Toast('World', 'error', 8000));

        $response = new Response();
        $this->dispatch($stack, $response, defaultDelay: 3000);

        $payload = json_decode(
            (string) $this->findCookie($response, 'turbo_toast')?->getValue(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertSame(3000, $payload[0]['delay']);
        self::assertSame(8000, $payload[1]['delay']);
    }

    public function testEmptyStackSetsNoCookie(): void
    {
        $response = new Response();
        $this->dispatch(new ToastStack(), $response);

        self::assertSame([], $response->headers->getCookies());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Nope'));

        $response = new Response();
        $this->dispatch($stack, $response, requestType: HttpKernelInterface::SUB_REQUEST);

        self::assertSame([], $response->headers->getCookies());
        self::assertNotSame([], $stack->drain(), 'a sub-request must not drain the stack');
    }

    public function testOversizedPayloadDropsTrailingToasts(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Short and sweet'), new Toast(str_repeat('x', 5000)));

        $response = new Response();
        $this->dispatch($stack, $response);

        $payload = json_decode(
            (string) $this->findCookie($response, 'turbo_toast')?->getValue(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertCount(1, $payload);
        self::assertSame('Short and sweet', $payload[0]['message']);
    }

    public function testSingleOversizedToastSetsNoCookie(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast(str_repeat('x', 5000)));

        $response = new Response();
        $this->dispatch($stack, $response);

        self::assertSame([], $response->headers->getCookies());
    }

    public function testCustomCookieName(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Hi'));

        $response = new Response();
        $this->dispatch($stack, $response, cookieName: 'my_flashes');

        self::assertNotNull($this->findCookie($response, 'my_flashes'));
    }

    public function testServerErrorsDiscardTheToastsAndSetNoCookie(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Item saved')); // ...but the flush actually failed

        $response = new Response(status: Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->dispatch($stack, $response);

        self::assertSame([], $response->headers->getCookies());
        self::assertSame([], $stack->drain(), 'toasts of a failed request must be discarded, not deferred');
    }

    public function testClientErrorsStillTransportTheToasts(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Photo deleted'));

        $response = new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->dispatch($stack, $response);

        self::assertNotNull($this->findCookie($response, 'turbo_toast'));
    }

    public function testCookieIsSecureOnHttpsRequests(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Hi'));

        $response = new Response();
        $this->dispatch($stack, $response, request: Request::create('https://example.com/'));

        self::assertTrue($this->findCookie($response, 'turbo_toast')?->isSecure());
    }

    public function testCookieIsNotSecureOnHttpRequests(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Hi'));

        $response = new Response();
        $this->dispatch($stack, $response, request: Request::create('http://localhost/'));

        self::assertFalse($this->findCookie($response, 'turbo_toast')?->isSecure());
    }

    public function testResponseCarryingTheCookieIsMadePrivate(): void
    {
        $stack = new ToastStack();
        $stack->push(new Toast('Hi'));

        $response = new Response();
        $response->setPublic();
        $response->headers->addCacheControlDirective('s-maxage', '3600');

        $this->dispatch($stack, $response);

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->hasCacheControlDirective('s-maxage'));
    }

    public function testResponseWithoutCookieIsLeftUntouched(): void
    {
        $response = new Response();
        $response->setPublic();

        $this->dispatch(new ToastStack(), $response);

        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    private function dispatch(
        ToastStack $stack,
        Response $response,
        string $cookieName = 'turbo_toast',
        int $defaultDelay = 5000,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
        ?Request $request = null,
    ): void {
        $subscriber = new ToastCookieSubscriber($stack, $cookieName, $defaultDelay);

        $subscriber->onKernelResponse(new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request ?? new Request(),
            $requestType,
            $response,
        ));
    }

    private function findCookie(Response $response, string $name): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($name === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }
}
