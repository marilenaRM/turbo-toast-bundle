# TurboToastBundle

**Flash messages that keep your pages HTTP-cacheable.**

[**Live overview →**](https://marilenarm.github.io/turbo-toast-bundle/) · [**Runnable demo app →**](https://github.com/marilenaRM/turbo-toast-demo) — session-free flows you can click through.

A page that touches the session cannot be stored by a shared HTTP cache — Symfony
marks it `private`, and Varnish or your CDN will never serve it. The classic
`$this->addFlash()` does exactly that: it drags the session (and its lock) into
otherwise stateless pages, just to display "Item saved". One flash message and
your anonymous, perfectly cacheable page becomes uncacheable.

This bundle takes the flash out of the session entirely, with two transports:

- **Turbo Stream** (AJAX/Turbo flows): the toast is generated and rendered in the
  same response, appended to the DOM, then auto-dismissed by a small **Stimulus**
  controller. No redirect, no storage at all.
- **Short-lived cookie** (classic full-page redirects): deferred toasts are
  serialized into a cookie at `kernel.response` time and consumed client-side by
  the container's Stimulus controller on the next page load. The redirected-to
  page's HTML stays generic — **and stays cacheable**.

What you get on session-free pages:

- **Full-page caching** behind Varnish/CDN keeps working, flash messages included —
  the message travels next to the page (cookie), not inside it.
- **Real parallelism**: concurrent requests (e.g. several lazy Turbo Frames) no
  longer serialize on the PHP session lock.
- **Stateless routes stay stateless**: no session cookie is ever created just to
  show a notification.

## When (not) to use it

Be honest with your profiler before adopting this. The session lock is paid once
per request, *whatever* opens the session:

- **Authenticated pages** (firewall loads the token from the session), classic
  session-based CSRF, locale or cart in session: the session opens anyway, so
  removing flashes from it gains you **nothing** — keep `addFlash()` there if you
  like it.
- **Anonymous, cacheable pages** (catalogs, content sites behind a CDN, stateless
  forms with [stateless CSRF](https://symfony.com/blog/new-in-symfony-7-2-stateless-csrf),
  lazy-frame-heavy pages): this is where the bundle shines — flashes were the last
  thing forcing a session, and now nothing does.

Profile first (Blackfire: look for `session_start` and serialized concurrent
requests), then decide.

## Requirements

- PHP >= 8.3, Symfony 7.x
- `symfony/ux-turbo` and `symfony/stimulus-bundle`

## Installation

```bash
composer require marilenarm/turbo-toast-bundle
```

Register the bundle (Flex does it automatically):

```php
// config/bundles.php
return [
    // ...
    MarilenaRM\TurboToastBundle\MarilenaRMTurboToastBundle::class => ['all' => true],
];
```

Add the toast container to your base layout:

```twig
{# templates/base.html.twig, inside <body> #}
{{ turbo_toast_container() }}
```

The function renders the container div from the bundle configuration — DOM id,
cookie name and Stimulus identifiers are injected from PHP, so YAML changes can
never drift apart from the JS side. The rendered markup is `data-turbo-permanent`
(existing toasts survive Turbo Drive navigations) and `aria-live="polite"`
(inserted toasts are announced by screen readers).

> [!NOTE]
> Controllers auto-registered from UX packages get **namespaced Stimulus
> identifiers**: `marilenarm--turbo-toast--toast` and
> `marilenarm--turbo-toast--toast-container`. If you need full control over the
> container markup, write the div manually and keep its Stimulus values in sync
> with the bundle configuration yourself.

Import the styles (optional — override freely):

```css
/* assets/styles/app.css */
@import '~@marilenarm/turbo-toast/styles/toast.css';
```

## Usage

Use the trait in any controller:

```php
use MarilenaRM\TurboToastBundle\Controller\TurboToastTrait;

final class ItemController extends AbstractController
{
    use TurboToastTrait;

    #[Route('/items', methods: ['POST'])]
    public function create(Request $request): Response
    {
        // ... persist

        return $this->toast('Item saved');
        // or: $this->toast('Oops', 'error');
        // or: $this->toast('Coming soon', 'info', delay: 8000);
    }
}
```

Emit several at once:

```php
use MarilenaRM\TurboToastBundle\Toast\Toast;

return $this->toasts(
    new Toast('Profile updated'),
    new Toast('A confirmation email has been sent', 'info'),
);
```

Compose the toast with other streams (append a row **and** notify) by including the
partial in your own `*.stream.html.twig`:

```twig
<turbo-stream action="append" target="items">
    <template>{{ include('item/_row.html.twig', { item: item }) }}</template>
</turbo-stream>

{{ include('@MarilenaRMTurboToast/toast.html.twig', {
    message: 'Item saved', type: 'success', delay: 5000, controller: 'toast',
}) }}
```

Not in a controller? Inject `MarilenaRM\TurboToastBundle\Toast\ToastRenderer` and call
`->render(new Toast(...))`.

### Classic redirects (non-Turbo flows)

When a flow performs a full-page `RedirectResponse` (post-login redirect, OAuth or
payment callbacks, `data-turbo="false"` links, locale switch...), there is no Turbo
Stream to render. Use `deferToast()` instead: the toast is transported by a
short-lived cookie and displayed on the next page load — still no session.

```php
#[Route('/login', methods: ['POST'])]
public function login(): Response
{
    // ... authenticate

    $this->deferToast('Welcome back!');

    return $this->redirectToRoute('dashboard');
}
```

Two explicit verbs, no magic:

| You return | Use |
|---|---|
| a Turbo Stream response | `toast()` / `toasts()` |
| a `RedirectResponse` (or any full page) | `deferToast()` before returning |

`toast()` throws a `LogicException` when the current request does not accept
Turbo Streams (no `text/vnd.turbo-stream.html` in the `Accept` header) — a
stream rendered there would reach the browser as raw markup. Turbo forms send
that header automatically; for anything else, use `deferToast()`.

How the cookie transport behaves:

- serialized at `kernel.response` by `ToastCookieSubscriber` (`SameSite=Lax`,
  `Secure` on HTTPS, not `HttpOnly` — the JS must read it); the response is
  forced `private` so the `Set-Cookie` never enters a shared HTTP cache;
- consumed and **cleared before rendering** by the `toast-container` controller
  (initial load, every `turbo:load`, and non-Turbo navigations), so Turbo cache
  restores never replay a toast;
- rendered with `textContent` only — the cookie is client-modifiable, treat it as
  untrusted display text and never put sensitive data in it;
- capped at ~3.8 KB url-encoded; trailing toasts beyond the budget are dropped;
- never set on 5xx responses: a request that ended in a server error discards
  its queued toasts instead of promising success on the next page.

Each of these addresses a concrete failure scenario — see the
[security & hardening design notes](docs/hardening.md) for the full threat
model and the reasoning behind every protection.

### Customizing cookie-rendered toasts

Two hooks, both keeping the message XSS-safe (`textContent` only):

**Template** — write the container manually (instead of `turbo_toast_container()`)
and put a `<template>` target inside it. Its root element is cloned per toast,
receives the `toast--{type}` class and the delay value; the message lands in the
`[data-toast-message]` element (or the root when none is declared):

```twig
<div id="toasts" aria-live="polite" data-turbo-permanent
     {{ stimulus_controller('marilenarm/turbo-toast/toast-container') }}>
    <template data-marilenarm--turbo-toast--toast-container-target="template">
        <div class="my-toast" data-controller="marilenarm--turbo-toast--toast">
            <twig:ux:icon name="lucide:check" />
            <span data-toast-message></span>
        </div>
    </template>
</div>
```

**Event** — take over rendering entirely by cancelling the
`marilenarm--turbo-toast--toast-container:append` event:

```js
document.addEventListener('marilenarm--turbo-toast--toast-container:append', (event) => {
    event.preventDefault();
    myToastLibrary.show(event.detail.toast.message, event.detail.toast.type);
});
```

## Profiler

In debug mode, a **Turbo Toast** panel appears in the Symfony profiler: every
toast emitted during the request, per transport (Turbo Stream / cookie), with
the queued / transported / discarded counts for the cookie path. Zero overhead
outside `kernel.debug` — the traceable decorators are only wired there.

## Configuration

```yaml
# config/packages/marilena_rm_turbo_toast.yaml
marilena_rm_turbo_toast:
    target: toasts            # DOM id of the container
    controller_name: marilenarm/turbo-toast/toast  # stimulus_controller() notation
    default_delay: 5000       # auto-dismiss (ms), 0 to disable
    stream_template: '@MarilenaRMTurboToast/toast.stream.html.twig'
    cookie_name: turbo_toast  # cookie used by deferToast() across redirects
```

---

If this bundle saved your pages from the session lock, you can
[buy me a coffee](https://ko-fi.com/marilenarm) ☕
