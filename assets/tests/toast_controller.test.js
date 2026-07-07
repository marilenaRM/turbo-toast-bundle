import { afterEach, describe, expect, it } from 'vitest';
import { Application } from '@hotwired/stimulus';
import ToastController from '../src/controller.js';

const nextTick = () => new Promise((resolve) => setTimeout(resolve, 0));
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

let application;

const mount = async (html) => {
    document.body.innerHTML = html;
    application = Application.start();
    application.register('toast', ToastController);
    await nextTick();

    return document.body.firstElementChild;
};

afterEach(async () => {
    document.body.innerHTML = '';
    await nextTick();
    application?.stop();
});

describe('toast controller', () => {
    it('fades the toast in on connect', async () => {
        const el = await mount('<div data-controller="toast" data-toast-delay-value="0">Hi</div>');

        await sleep(50); // let requestAnimationFrame fire

        expect(el.classList.contains('toast--in')).toBe(true);
    });

    it('auto-dismisses after the configured delay and leaves the DOM after the transition', async () => {
        const el = await mount('<div data-controller="toast" data-toast-delay-value="10">Hi</div>');

        await sleep(80);

        expect(el.classList.contains('toast--in')).toBe(false);

        el.dispatchEvent(new Event('transitionend'));

        expect(document.body.contains(el)).toBe(false);
    });

    it('does not auto-dismiss when the delay is 0', async () => {
        const el = await mount('<div data-controller="toast" data-toast-delay-value="0">Hi</div>');

        await sleep(80);

        expect(el.classList.contains('toast--in')).toBe(true);
        expect(document.body.contains(el)).toBe(true);
    });

    it('dismisses on click', async () => {
        const el = await mount(
            '<div data-controller="toast" data-toast-delay-value="0" data-action="click->toast#dismiss">Hi</div>',
        );

        await sleep(50);
        el.click();

        expect(el.classList.contains('toast--in')).toBe(false);

        el.dispatchEvent(new Event('transitionend'));

        expect(document.body.contains(el)).toBe(false);
    });
});
