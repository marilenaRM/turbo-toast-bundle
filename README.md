# TurboToastBundle

**Flash messages that keep your pages HTTP-cacheable.**

[**Live overview →**](https://marilenarm.github.io/turbo-toast-bundle/) · [**Runnable demo app →**](https://marilenarm.github.io/turbo-toast-demo/), where you can click through every session-free flow.

A page that touches the session cannot be stored by a shared HTTP cache: Symfony
marks it `private`, so Varnish or your CDN will never serve it. The classic
`$this->addFlash()` does exactly that. It drags the session (and its lock) into
otherwise stateless pages just to display "Item saved", and your anonymous page
stops being cacheable because of a one-line notification.

This bundle takes the flash out of the session entirely, with two transports:

- **Turbo Stream** (AJAX/Turbo flows): the toast is generated and rendered in the
  same response, appended to the DOM, then auto-dismissed by a small Stimulus
  controller. There is no redirect and nothing gets stored.
- **Short-lived cookie** (classic full-page redirects): deferred toasts are
  serialized into a cookie at `kernel.response` time, and the container's
  Stimulus controller reads them client-side on the next page load. The page you
  redirect to keeps generic HTML, so it stays cacheable.

On pages that no longer open a session, three things change:

- Full-page caching behind Varnish or a CDN keeps working with flash messages,
  because the message travels in a cookie next to the page instead of inside it.
- Concurrent requests (several lazy Turbo Frames, for instance) stop queuing
  behind the PHP session lock.
- No session cookie is ever created just to show a notification.

## When (not) to use it

Be honest with your profiler before adopting this. The session lock is paid once
per request, *whatever* opens the session:

- **Authenticated pages** (firewall loads the token from the session), classic
  session-based CSRF, locale or cart in session: the session opens anyway, so
  removing flashes from it gains you nothing. Keep `addFlash()` there if you like
  it.
- **Anonymous, cacheable pages** (catalogs, content sites behind a CDN, stateless
  forms with [stateless CSRF](https://symfony.com/blog/new-in-symfony-7-2-stateless-csrf),
  lazy-frame-heavy pages): this is where the bundle pays off, as long as flashes
  were the last thing forcing a session open.

Profile first (Blackfire: look for `session_start` and serialized concurrent
requests), then decide.

## Requirements

- PHP >= 8.3, Symfony 6.4 (LTS), 7.x or 8.x
- `symfony/ux-turbo` and `symfony/stimulus-bundle`, UX 2.13+ or UX 3.x

Symfony 8 itself requires PHP >= 8.4.1, so that pairing rules out PHP 8.3. The
same goes for UX 3, which requires PHP >= 8.4: on PHP 8.3, Composer keeps you
on UX 2.

The pairings CI actually runs are listed in `MATRIX` (see
[Contributing](#contributing)): a sparse grid hitting both ends of the PHP range
each Symfony branch supports, plus a `--prefer-lowest` run resolving the oldest
dependency set the constraints allow.

Symfony 7.0–7.3 are accepted by the version constraint, since nothing in the
bundle needs a newer API, but they are not tested: those branches are end-of-life
and carry unpatched security advisories, so Composer refuses to install them by
default.

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

The function renders the container div from the bundle configuration. DOM id,
cookie name and Stimulus identifiers are injected from PHP, so a YAML change can
never drift apart from the JS side. The rendered markup is `data-turbo-permanent`
(existing toasts survive Turbo Drive navigations) and `aria-live="polite"`
(inserted toasts are announced by screen readers).

> [!NOTE]
> Controllers auto-registered from UX packages get **namespaced Stimulus
> identifiers**: `marilenarm--turbo-toast--toast` and
> `marilenarm--turbo-toast--toast-container`. If you need full control over the
> container markup, write the div manually and keep its Stimulus values in sync
> with the bundle configuration yourself.

Import the styles if you want them (they are meant to be overridden):

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

To append a row *and* notify in the same response, include the toast partial in
your own `*.stream.html.twig`:

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
Stream to render. Use `deferToast()` instead: a short-lived cookie carries the
toast to the next page load, still without touching the session.

```php
#[Route('/login', methods: ['POST'])]
public function login(): Response
{
    // ... authenticate

    $this->deferToast('Welcome back!');

    return $this->redirectToRoute('dashboard');
}
```

The verb follows what your controller returns, nothing is guessed:

| You return | Use |
|---|---|
| a Turbo Stream response | `toast()` / `toasts()` |
| a `RedirectResponse` (or any full page) | `deferToast()` before returning |

`toast()` throws a `LogicException` when the current request does not accept
Turbo Streams (no `text/vnd.turbo-stream.html` in the `Accept` header), because a
stream rendered there would reach the browser as raw markup. Turbo forms send
that header automatically; for anything else, use `deferToast()`.

How the cookie transport behaves:

- serialized at `kernel.response` by `ToastCookieSubscriber` (`SameSite=Lax`,
  `Secure` on HTTPS, not `HttpOnly` since the JS must read it); the response is
  forced `private` so the `Set-Cookie` never enters a shared HTTP cache;
- consumed and cleared *before* rendering by the `toast-container` controller
  (initial load, every `turbo:load`, and non-Turbo navigations), so Turbo cache
  restores never replay a toast;
- rendered with `textContent` only: the client can modify the cookie, so treat
  its content as untrusted display text and never put sensitive data in it;
- capped at ~3.8 KB url-encoded; trailing toasts beyond the budget are dropped;
- never set on 5xx responses: a request that ended in a server error discards
  its queued toasts instead of promising success on the next page.

Each rule answers a concrete failure scenario. The
[security & hardening design notes](docs/hardening.md) walk through the threat
model and explain why every protection is there.

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
the queued / transported / discarded counts for the cookie path. The traceable
decorators are only wired under `kernel.debug`, so production pays nothing.

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

## Contributing

The test pipeline runs in [Dagger](https://dagger.io), so the matrix you run on
your laptop is the one CI runs. That closes the "works on my machine" gap, and the
Symfony version list is not duplicated in a workflow file. With the Dagger CLI and a container
runtime installed:

```bash
dagger call ci                              # everything CI runs
dagger call matrix                          # the PHP / Symfony matrix
dagger call test --php=8.3 --symfony='6.4.*' --ux=2 --prefer-lowest  # a single cell
dagger call lint                            # Twig lint
dagger call assets                          # dist sync + Stimulus tests
```

The tested combinations live in `MATRIX`, in
[`.dagger/src/turbo_toast/main.py`](.dagger/src/turbo_toast/main.py). Each cell
pins Symfony through `symfony/flex` and the UX major through `composer update
--with`, then asserts that both really landed on the versions under test, so a
cell cannot go green while quietly resolving a newer Symfony or the other UX
major.

Widening the supported range means editing `composer.json`, adding a row to
`MATRIX`, and updating the Requirements section above plus the version strings
in `docs/index.html` (`grep -n 'Symfony 6.4' docs/index.html` finds them).

---

If this bundle saved your pages from the session lock, you can
[buy me a coffee](https://ko-fi.com/marilenarm) ☕
