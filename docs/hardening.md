# Security & hardening design notes

Every protection in this bundle answers a concrete failure scenario. This page
documents each one: what could go wrong, how the bundle prevents it, and where
the code lives.

Three principles drive all of them:

1. **The cookie is untrusted input** — it lives on the client, unsigned, and
   anyone can rewrite it in the DevTools console.
2. **A toast is per-user data** — it must never reach another user, whether
   through a shared HTTP cache or a long-running PHP worker.
3. **A toast must never lie** — no success message after a failed request, no
   replayed message on a cache restore.

| # | Failure scenario | Protection | Where |
|---|---|---|---|
| 1 | Tampered cookie injects HTML → XSS | `textContent` only, type filtered to `[\w-]` | [toast_container_controller.js](../assets/src/toast_container_controller.js) |
| 2 | Cookie read over plain HTTP (downgrade) | `Secure` flag on HTTPS requests | [ToastCookieSubscriber.php](../src/EventSubscriber/ToastCookieSubscriber.php) |
| 3 | Cookie attached to cross-site requests | `SameSite=Lax` | [ToastCookieSubscriber.php](../src/EventSubscriber/ToastCookieSubscriber.php) |
| 4 | Oversized cookie silently rejected by the browser | ~3.8 KB budget, graceful overflow | [ToastCookieSubscriber.php](../src/EventSubscriber/ToastCookieSubscriber.php) |
| 5 | `Set-Cookie` cached by Varnish/CDN → served to everyone | Response forced `private`, `s-maxage` stripped | [ToastCookieSubscriber.php](../src/EventSubscriber/ToastCookieSubscriber.php) |
| 6 | Undrained stack leaks to the next request in worker runtimes | `ResetInterface` + `kernel.reset` tag | [ToastStack.php](../src/Toast/ToastStack.php) |
| 7 | "Saved!" shown after the request actually crashed | 5xx responses discard queued toasts | [ToastCookieSubscriber.php](../src/EventSubscriber/ToastCookieSubscriber.php) |
| 8 | Toast replayed on Turbo cache restore (back button) | Cookie cleared *before* rendering | [toast_container_controller.js](../assets/src/toast_container_controller.js) |
| 9 | Raw `<turbo-stream>` markup served as a full page | Renderer refuses non-Turbo requests | [ToastRenderer.php](../src/Toast/ToastRenderer.php) |
| 10 | Screen readers never announce (or shout) toasts | Pre-existing `aria-live` container, `role` by type | [container.html.twig](../templates/container.html.twig), [toast.html.twig](../templates/toast.html.twig) |

## 1. The cookie is untrusted input

The deferred-toast cookie is deliberately **not `HttpOnly`** (the Stimulus
controller must read it) and **not signed** (signing display-only text would be
over-engineering). The consequence is assumed: anyone can craft its content:

```js
document.cookie = 'turbo_toast=' + encodeURIComponent(
    JSON.stringify([{ message: '<img src=x onerror=alert(1)>' }])
);
```

If the container controller inserted messages with `innerHTML`, that payload
would execute — a self-inflicted XSS, and a real one as soon as anything else
(a browser extension, another script) can write cookies. Instead:

- **`el.textContent = String(message)`** — the browser treats the string as
  plain text, never as markup. There is no HTML path from the cookie to the DOM.
- **`type` is filtered to `[\w-]`** before landing in a CSS class name and in
  the `role` attribute.
- **`delay` goes through `parseInt()`** and falls back to `0`.
- Malformed JSON or a non-array payload is silently discarded (`try/catch`
  around `JSON.parse`).

The flip side of an unsigned, client-readable cookie: **never put sensitive
data in a toast message**. Treat it as public display text.

### Transport attributes

- **`Secure` on HTTPS** — derived from `Request::isSecure()`. In production the
  cookie never travels over plain HTTP (protects against downgrade attacks);
  local HTTP development keeps working.
- **`SameSite=Lax`** — the cookie is not attached to cross-site subresource
  requests. Low stakes for display text, but it is the correct default for any
  cookie.

### Size budget

Browsers cap a cookie at ~4096 bytes for the **whole `Set-Cookie` line**. An
oversized value is silently truncated or dropped — which would hand corrupted
JSON to the client. The subscriber measures the **url-encoded** payload against
a conservative 3.8 KB budget and drops trailing toasts until it fits. If a
single toast is already over budget, no cookie is set at all: a missing toast
beats a broken one.

## 2. A toast is per-user data

### Shared HTTP caches

The scenario: `deferToast()` is called on a response that is (or becomes)
`Cache-Control: public` behind Varnish or a CDN. The `Set-Cookie` header —
carrying a message meant for **one** user — enters the shared cache and is
replayed to every subsequent visitor. Everyone gets "Welcome back, Marilena".

This is exactly the leak Symfony prevents for sessions: `AbstractSessionListener`
forces the response `private` whenever the session was used. The subscriber
applies the same rule: whenever it sets the cookie, it calls `setPrivate()` and
strips any `s-maxage` directive.

Note the symmetry with the bundle's purpose: it exists to keep pages cacheable,
so it must be beyond reproach on the one response it makes uncacheable — the
one carrying the cookie (typically a 302 redirect, worthless to cache anyway).

### Long-running workers

Under classic PHP-FPM a process dies after each request; stateful services
cannot leak. Under **FrankenPHP worker mode, RoadRunner or Swoole**, the kernel
and its services survive across requests. If request A pushes a toast and dies
before `kernel.response` (fatal error, timeout), the undrained `ToastStack`
stays in memory — and request B, **possibly another user on the same worker**,
inherits A's toast.

`ToastStack` implements `ResetInterface`; between two requests the runtime
calls `reset()` on every service tagged `kernel.reset`, emptying the stack.
The tag is set explicitly in `config/services.php` because autoconfiguration
(which would tag any `ResetInterface` automatically) does not apply to services
defined by a bundle.

## 3. A toast must never lie

### Server errors

```php
$this->deferToast('Item saved');
return $this->redirectToRoute('list');   // ...but flush() threw
```

The toast is queued **before** the failure happens. The exception produces a
500 page — and `kernel.response` still fires for that error response. Without a
guard, the cookie would ship and the next page would display "Item saved" while
nothing was persisted.

The subscriber checks `Response::isServerError()` and **drains-and-discards**
the queued toasts (draining matters: leaving them in the stack would leak them
into later processing). This is intentionally *stricter* than the session
`FlashBag`, which persists its messages even across a 500.

Client errors (4xx) still transport their toasts: a 422 form re-render is a
*controlled* response where a toast ("Photo deleted") can be legitimate
alongside validation errors.

### Replay on cache restores

Turbo Drive restores pages from its snapshot cache (back button, `restore`
visits) and fires `turbo:load` again each time. If the container controller
cleared the cookie *after* rendering — or not at all — every restore would
replay the toast. The controller therefore reads the cookie, **clears it
immediately, then renders**: by the second `turbo:load` there is nothing left
to replay.

### Raw stream markup

`toast()` returns a `text/vnd.turbo-stream.html` response. Served to a client
that did not ask for it (no JS, plain form POST, curl), that response would
render as raw `<turbo-stream>` markup — a broken page. The renderer checks the
`Accept` header and throws an actionable `LogicException` pointing to
`deferToast()` instead. Failing loudly at development time beats shipping a
page of angle brackets.

## 4. Accessibility

Two details most toast systems get wrong:

- **The live region must pre-exist.** Screen readers only announce dynamically
  inserted content when it lands *inside* an `aria-live` region that was already
  in the DOM. That is why `aria-live="polite"` sits on the **container**
  (rendered with the page by `turbo_toast_container()`), not on the inserted
  toast.
- **`role="alert"` implies `aria-live="assertive"`** — it interrupts whatever
  the screen reader is currently saying. Justified for an error, hostile for
  "Item saved". Toasts therefore get `role="status"` (polite announcement) for
  success/info/warning and `role="alert"` only for the `error` type — applied
  in both rendering paths, the Twig template and the JS `append()`.
