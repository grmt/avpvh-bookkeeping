// Stand-ins for the browser features jsdom lacks, shared by the tests.
import { vi } from 'vitest';

export function browserStandIns({ width = 1000, height = 2000 } = {}) {
    const proto = globalThis.HTMLDialogElement?.prototype;
    if (proto) {
        proto.showModal = function () { this.open = true; };
        proto.close = function () {
            this.open = false;
            this.dispatchEvent(new Event('close'));
        };
    }
    Object.defineProperty(HTMLImageElement.prototype, 'naturalWidth', { configurable: true, get: () => width });
    Object.defineProperty(HTMLImageElement.prototype, 'naturalHeight', { configurable: true, get: () => height });
    Object.defineProperty(HTMLImageElement.prototype, 'complete', { configurable: true, get: () => true });
    HTMLImageElement.prototype.getBoundingClientRect = function () {
        const shown = Number.parseFloat(this.style.width) || width;
        return { left: 0, top: 0, width: shown, height: shown * (height / width) };
    };
    Element.prototype.setPointerCapture = () => {};
    HTMLCanvasElement.prototype.getContext = () => ({ fillRect: vi.fn(), drawImage: vi.fn(), fillStyle: '' });
    HTMLCanvasElement.prototype.toBlob = function (done) { done(new Blob(['png'], { type: 'image/png' })); };
    URL.createObjectURL = vi.fn(() => 'blob:receipt');
    URL.revokeObjectURL = vi.fn();
    globalThis.DataTransfer = class {
        constructor() {
            this.list = [];
            this.items = { add: (file) => this.list.push(file) };
        }
        get files() { return this.list; }
    };
}

/** Lets pending promise callbacks run. */
export async function settle() {
    for (let i = 0; i < 5; i++) {
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

export function pointer(target, type, x, y, extra = {}) {
    target.dispatchEvent(new MouseEvent(type, { bubbles: true, button: 0, clientX: x, clientY: y, ...extra }));
}
