import { describe, expect, it } from 'vitest';
import { axialToPixel, axialRound, pixelToAxial } from '../../src/lib/hexMath';

describe('hex math helpers', () => {
  it('converts axial coords to pixels deterministically', () => {
    const { x, y } = axialToPixel(2, 1, 80);
    expect(x).toBeCloseTo(346.410, 3);
    expect(y).toBeCloseTo(120, 3);
  });

  it('rounds fractional axial coords to nearest discrete hex', () => {
    const rounded = axialRound(1.3, 0.7);
    expect(rounded).toEqual({ q: 1, r: 1 });
  });

  it('round-trips axial <-> pixel conversions', () => {
    const axial = { q: 2, r: -1 };
    const pixel = axialToPixel(axial.q, axial.r, 80);
    const { q, r } = pixelToAxial(pixel.x, pixel.y, 80);
    const rounded = axialRound(q, r);
    expect(rounded).toEqual(axial);
  });
});
