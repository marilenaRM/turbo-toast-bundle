import { Controller } from '@hotwired/stimulus';

/*
 * Toast container controller: consumes toasts deferred through the cookie
 * (classic full-page redirects) and renders them into the container.
 *
 * The container is expected to be `data-turbo-permanent`, so connect() fires
 * only once per real page load — we also listen to `turbo:load` to consume
 * the cookie after every Turbo Drive visit.
 *
 * Security: the cookie is client-modifiable. Messages are rendered with
 * textContent (never innerHTML) and the type is restricted to [\w-].
 */
export default class extends Controller {
    static values = {
        cookieName: { type: String, default: 'turbo_toast' },
        // Normalized Stimulus identifier of the auto-registered toast controller.
        toastController: { type: String, default: 'marilenarm--turbo-toast--toast' },
    };

    initialize() {
        this.consume = this.consume.bind(this);
    }

    connect() {
        this.consume();
        document.addEventListener('turbo:load', this.consume);
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.consume);
    }

    consume() {
        for (const toast of this.readAndClearCookie()) {
            this.append(toast);
        }
    }

    readAndClearCookie() {
        const prefix = `${this.cookieNameValue}=`;
        const entry = document.cookie.split('; ').find((c) => c.startsWith(prefix));
        if (!entry) {
            return [];
        }

        // Clear before rendering: a second turbo:load (cache restore) must not replay.
        document.cookie = `${this.cookieNameValue}=; path=/; max-age=0`;

        try {
            const parsed = JSON.parse(decodeURIComponent(entry.slice(prefix.length)));

            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    }

    append({ message, type = 'success', delay = 5000 }) {
        const name = this.toastControllerValue;
        const el = document.createElement('div');

        const safeType = String(type).replace(/[^\w-]/g, '');

        el.className = `toast toast--${safeType}`;
        el.setAttribute('role', 'error' === safeType ? 'alert' : 'status');
        el.setAttribute('data-controller', name);
        el.setAttribute(`data-${name}-delay-value`, String(parseInt(delay, 10) || 0));
        el.setAttribute('data-action', `click->${name}#dismiss`);
        el.textContent = String(message); // never innerHTML: the cookie is untrusted input

        this.element.append(el);
    }
}
