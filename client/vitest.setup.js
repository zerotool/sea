import { vi } from 'vitest';

// Minimal canvas mock for unit tests
if (typeof window !== 'undefined') {
  window.HTMLCanvasElement.prototype.getContext = vi.fn(() => ({
    beginPath: () => {},
    moveTo: () => {},
    lineTo: () => {},
    closePath: () => {},
    fill: () => {},
    stroke: () => {},
    arc: () => {},
    clearRect: () => {},
    fillText: () => {},
    set fillStyle(value) {},
    set font(value) {},
    set textAlign(value) {},
    set textBaseline(value) {},
  }));
}
