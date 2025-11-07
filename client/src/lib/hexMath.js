export function axialToPixel(q, r, size) {
  return {
    x: size * Math.sqrt(3) * (q + r / 2),
    y: size * 1.5 * r,
  };
}

export function pixelToAxial(x, y, size) {
  return {
    q: ((Math.sqrt(3) / 3) * x - (1 / 3) * y) / size,
    r: ((2 / 3) * y) / size,
  };
}

function axialToCube(q, r) {
  return { x: q, z: r, y: -q - r };
}

function cubeToAxial(x, y, z) {
  return { q: x, r: z };
}

function cubeRound(x, y, z) {
  let rx = Math.round(x);
  let ry = Math.round(y);
  let rz = Math.round(z);
  const xDiff = Math.abs(rx - x);
  const yDiff = Math.abs(ry - y);
  const zDiff = Math.abs(rz - z);
  if (xDiff > yDiff && xDiff > zDiff) {
    rx = -ry - rz;
  } else if (yDiff > zDiff) {
    ry = -rx - rz;
  } else {
    rz = -rx - ry;
  }
  return { x: rx, y: ry, z: rz };
}

export function axialRound(q, r) {
  const cube = axialToCube(q, r);
  const rounded = cubeRound(cube.x, cube.y, cube.z);
  return cubeToAxial(rounded.x, rounded.y, rounded.z);
}
