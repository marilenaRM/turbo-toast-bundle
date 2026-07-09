import { Controller } from '@hotwired/stimulus';

/*
 * Toast controller: fades a toast in on connect, auto-dismisses after a delay,
 * removes itself from the DOM once the exit transition ends
 * (immediately when no transition applies to the element).
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
        clearTimeout(this.removeTimeout);
    }

    dismiss() {
        cancelAnimationFrame(this.frame);
        clearTimeout(this.timeout);
        this.element.classList.remove('toast--in');

        const duration = this.transitionDurationMs();

        if (duration === 0) {
            this.element.remove();

            return;
        }

        const remove = () => {
            clearTimeout(this.removeTimeout);
            this.element.remove();
        };

        this.element.addEventListener('transitionend', remove, { once: true });
        // Safety net: transitionend never fires when the transition is
        // interrupted or the element is hidden before it completes.
        this.removeTimeout = setTimeout(remove, duration + 50);
    }

    transitionDurationMs() {
        const style = getComputedStyle(this.element);
        const longest = (value) => Math.max(...value.split(',').map((part) => parseFloat(part) || 0));

        return (longest(style.transitionDuration || '0s') + longest(style.transitionDelay || '0s')) * 1000;
    }
}
