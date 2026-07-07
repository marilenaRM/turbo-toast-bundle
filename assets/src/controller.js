import { Controller } from '@hotwired/stimulus';

/*
 * Toast controller: fades a toast in on connect, auto-dismisses after a delay,
 * removes itself from the DOM once the exit transition ends.
 */
export default class extends Controller {
    static values = {
        delay: { type: Number, default: 5000 },
    };

    connect() {
        this.frame = requestAnimationFrame(() => this.element.classList.add('toast--in'));

        if (this.delayValue > 0) {
            this.timeout = setTimeout(() => this.dismiss(), this.delayValue);
        }
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        clearTimeout(this.timeout);
    }

    dismiss() {
        cancelAnimationFrame(this.frame);
        clearTimeout(this.timeout);
        this.element.classList.remove('toast--in');
        this.element.addEventListener(
            'transitionend',
            () => this.element.remove(),
            { once: true },
        );
    }
}
