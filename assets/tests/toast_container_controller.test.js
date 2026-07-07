import { afterEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ToastContainerController from '../src/toast_container_controller.js';

const nextTick = () => new Promise((resolve) => setTimeout(resolve, 0));

const CONTAINER_HTML = `<div id="toasts" data-controller="toast-container"
    data-toast-container-cookie-name-value="turbo_toast"
    data-toast-container-toast-controller-value="toast"></div>`;

let application;

const setCookie = (payload) => {
    const value = typeof payload === 'string' ? payload : encodeURIComponent(JSON.stringify(payload));
    document.cookie = `turbo_toast=${value}; path=/`;
};

const mount = async (html = CONTAINER_HTML) => {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('toast-container', ToastContainerController);
    await nextTick();

    return document.getElementById('toasts');
};

afterEach(async () => {
    document.cookie = 'turbo_toast=; path=/; max-age=0';
    document.body.innerHTML = '';
    await nextTick();
    application?.stop();
});

describe('toast-container controller', () => {
    it('renders cookie-deferred toasts on connect', async () => {
        setCookie([{ message: 'Item saved', type: 'success', delay: 3000 }]);

        const container = await mount();

        expect(container.children).toHaveLength(1);

        const toast = container.firstElementChild;
        expect(toast.textContent).toBe('Item saved');
        expect(toast.className).toBe('toast toast--success');
        expect(toast.getAttribute('role')).toBe('status');
        expect(toast.getAttribute('data-controller')).toBe('toast');
        expect(toast.getAttribute('data-toast-delay-value')).toBe('3000');
        expect(toast.getAttribute('data-action')).toBe('click->toast#dismiss');
    });

    it('renders a tampered HTML payload as inert text (no XSS)', async () => {
        setCookie([{ message: '<img src=x onerror="window.__pwned = true">', type: 'success', delay: 0 }]);

        const container = await mount();

        expect(container.querySelector('img')).toBeNull();
        expect(container.firstElementChild.textContent).toContain('<img');
        expect(window.__pwned).toBeUndefined();
    });

    it('clears the cookie before rendering', async () => {
        setCookie([{ message: 'Once', type: 'success', delay: 0 }]);

        await mount();

        expect(document.cookie).not.toContain('turbo_toast');
    });

    it('does not replay toasts on Turbo cache restores', async () => {
        setCookie([{ message: 'Once', type: 'success', delay: 0 }]);

        const container = await mount();

        document.dispatchEvent(new Event('turbo:load'));
        document.dispatchEvent(new Event('turbo:load'));
        await nextTick();

        expect(container.children).toHaveLength(1);
    });

    it('consumes a cookie set after connect on the next turbo:load', async () => {
        const container = await mount();

        expect(container.children).toHaveLength(0);

        setCookie([{ message: 'After redirect', type: 'info', delay: 0 }]);
        document.dispatchEvent(new Event('turbo:load'));
        await nextTick();

        expect(container.children).toHaveLength(1);
        expect(container.firstElementChild.textContent).toBe('After redirect');
    });

    it('ignores a malformed cookie payload', async () => {
        setCookie('%7Bnot-json');

        const container = await mount();

        expect(container.children).toHaveLength(0);
        expect(document.cookie).not.toContain('turbo_toast');
    });

    it('ignores a non-array payload', async () => {
        setCookie({ message: 'not an array' });

        const container = await mount();

        expect(container.children).toHaveLength(0);
    });

    it('restricts the type to safe class-name characters', async () => {
        setCookie([{ message: 'Hi', type: 'success"><script>', delay: 0 }]);

        const container = await mount();

        expect(container.firstElementChild.className).toBe('toast toast--successscript');
    });

    it('uses role=alert for the error type only', async () => {
        setCookie([
            { message: 'Boom', type: 'error', delay: 0 },
            { message: 'Fine', type: 'info', delay: 0 },
        ]);

        const container = await mount();

        const [error, info] = container.children;
        expect(error.getAttribute('role')).toBe('alert');
        expect(info.getAttribute('role')).toBe('status');
    });

    it('normalizes a non-numeric delay to 0', async () => {
        setCookie([{ message: 'Hi', type: 'info', delay: 'soon' }]);

        const container = await mount();

        expect(container.firstElementChild.getAttribute('data-toast-delay-value')).toBe('0');
    });
});
