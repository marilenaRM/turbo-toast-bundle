import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ToastController from '../src/controller.js';

// jsdom only reflects inline styles in getComputedStyle, so this is how a
// consumer stylesheet with a transition on .toast is simulated here.
const TRANSITION = 'transition-property: opacity; transition-duration: 0.15s;';

let application;

// Drain microtasks so Stimulus connects the controller, without advancing the
// clock: the queued animation frame and any delay timer stay pending.
const connectControllers = () => vi.advanceTimersByTimeAsync(0);

const mount = async (html) => {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('toast', ToastController);
    await connectControllers();

    return document.body.firstElementChild;
};

const mountToast = ({ delay = 0, click = false, style = '' } = {}) => mount(
    `<div data-controller="toast" data-toast-delay-value="${delay}"`
    + `${click ? ' data-action="click->toast#dismiss"' : ''}`
    + `${style ? ` style="${style}"` : ''}>Hi</div>`,
);

const dismissByClick = async (el) => {
    await vi.advanceTimersByTimeAsync(20); // let the entrance animation frame run
    el.click();
};

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(async () => {
    application?.stop();
    document.body.innerHTML = '';
    vi.clearAllTimers();
    vi.useRealTimers();
});

describe('toast controller', () => {
    it('fades the toast in on connect', async () => {
        const el = await mountToast();

        await vi.advanceTimersByTimeAsync(20); // let requestAnimationFrame fire

        expect(el.classList.contains('toast--in')).toBe(true);
    });

    it('auto-dismisses after the configured delay and leaves the DOM after the transition', async () => {
        const el = await mountToast({ delay: 10, style: TRANSITION });

        await vi.advanceTimersByTimeAsync(10);

        expect(el.classList.contains('toast--in')).toBe(false);
        expect(document.body.contains(el)).toBe(true);

        el.dispatchEvent(new Event('transitionend'));

        expect(document.body.contains(el)).toBe(false);
    });

    it('does not auto-dismiss when the delay is 0', async () => {
        const el = await mountToast();

        await vi.advanceTimersByTimeAsync(100);

        expect(el.classList.contains('toast--in')).toBe(true);
        expect(document.body.contains(el)).toBe(true);
    });

    it('dismisses on click', async () => {
        const el = await mountToast({ click: true, style: TRANSITION });

        await dismissByClick(el);

        expect(el.classList.contains('toast--in')).toBe(false);
        expect(document.body.contains(el)).toBe(true);

        el.dispatchEvent(new Event('transitionend'));

        expect(document.body.contains(el)).toBe(false);
    });

    it('removes the toast immediately when no transition applies', async () => {
        const el = await mountToast({ click: true });

        await dismissByClick(el);

        expect(document.body.contains(el)).toBe(false);
    });

    it('ignores transitionend bubbling up from a child element', async () => {
        const el = await mountToast({ click: true, style: TRANSITION });
        const child = document.createElement('span');
        el.appendChild(child);

        await dismissByClick(el);

        // A descendant transition finishing must not tear the toast down early.
        child.dispatchEvent(new Event('transitionend', { bubbles: true }));

        expect(document.body.contains(el)).toBe(true);

        // The toast's own transition still removes it.
        el.dispatchEvent(new Event('transitionend'));

        expect(document.body.contains(el)).toBe(false);
    });

    it('removes the toast even if transitionend never fires', async () => {
        const el = await mountToast({ click: true, style: TRANSITION });

        await dismissByClick(el);

        expect(document.body.contains(el)).toBe(true);

        await vi.advanceTimersByTimeAsync(200); // 150ms transition + 50ms safety margin

        expect(document.body.contains(el)).toBe(false);
    });

    it('accounts for transition-delay in the fallback timeout', async () => {
        const el = await mountToast({ click: true, style: `${TRANSITION} transition-delay: 0.1s;` });

        await dismissByClick(el);

        await vi.advanceTimersByTimeAsync(299); // just under 150ms duration + 100ms delay + 50ms margin

        expect(document.body.contains(el)).toBe(true);

        await vi.advanceTimersByTimeAsync(1);

        expect(document.body.contains(el)).toBe(false);
    });
});
