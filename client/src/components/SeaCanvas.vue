<template>
  <canvas ref="canvasRef"></canvas>
</template>

<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { axialToPixel } from '../lib/hexMath';

const props = defineProps({
  wsBase: {
    type: String,
    required: true,
  },
});

const emit = defineEmits(['sector-updated', 'coords-updated', 'status', 'player-id']);

const canvasRef = ref(null);
let ctx;
// animation frame used for fleet interpolation

const PLAYER_STORAGE_KEY = 'sea-player-id';
const SHIP_RADIUS = 12;

const playerId = ref(localStorage.getItem(PLAYER_STORAGE_KEY) || '');
const state = {
  grid: null,
  hexes: [],
  ships: [],
  ship: { id: '', x: 0, y: 0, radius: SHIP_RADIUS, label: '🚢' },
  offsets: { x: 0, y: 0 },
  shipSpeed: 220,
};

const renderShips = new Map();
const shipAnimations = new Map();
let animationFrame = null;
let socket = null;
let reconnectTimeout = null;
let reconnectAttempts = 0;
let pendingResync = true;
const messageQueue = [];

function buildGrid(gridConfig) {
  state.hexes = [];
  for (let r = 0; r < gridConfig.rows; r += 1) {
    for (let q = 0; q < gridConfig.cols; q += 1) {
      const center = axialToPixel(q, r, gridConfig.hexSize);
      const label = gridConfig.labels[r][q];
      state.hexes.push({ q, r, center, label });
    }
  }
}

function computeOffsets() {
  if (!state.grid || !canvasRef.value) return;
  const { hexSize, rows, cols } = state.grid;
  const gridWidth = Math.sqrt(3) * hexSize * (cols - 0.5);
  const gridHeight = hexSize * 1.5 * (rows - 1) + 2 * hexSize;
  state.offsets.x = (canvasRef.value.width - gridWidth) / 2;
  state.offsets.y = (canvasRef.value.height - gridHeight) / 2;
}

function drawHex(hex, hexSize) {
  const { x: offsetX, y: offsetY } = state.offsets;
  const { center } = hex;
  ctx.beginPath();
  for (let i = 0; i < 6; i += 1) {
    const angle = ((60 * i) - 30) * (Math.PI / 180);
    const x = center.x + hexSize * Math.cos(angle) + offsetX;
    const y = center.y + hexSize * Math.sin(angle) + offsetY;
    if (i === 0) ctx.moveTo(x, y);
    else ctx.lineTo(x, y);
  }
  ctx.closePath();
  ctx.fillStyle = '#1274b3';
  ctx.globalAlpha = 0.7;
  ctx.fill();
  ctx.globalAlpha = 1;
  ctx.lineWidth = 2;
  ctx.strokeStyle = '#094361';
  ctx.stroke();
  ctx.fillStyle = '#ffffff';
  ctx.font = `${hexSize / 4}px sans-serif`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(hex.label, center.x + offsetX, center.y + offsetY);
}

function drawShips() {
  const { x: offsetX, y: offsetY } = state.offsets;
  renderShips.forEach((render, id) => {
    const screenX = render.x + offsetX;
    const screenY = render.y + offsetY;
    ctx.beginPath();
    ctx.arc(screenX, screenY, SHIP_RADIUS, 0, Math.PI * 2);
    ctx.fillStyle = id === state.ship.id ? '#ffd166' : '#8bc1ff';
    ctx.fill();
    ctx.font = `${SHIP_RADIUS * 1.1}px sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(id.substring(0, 2).toUpperCase(), screenX, screenY - 2);
  });
}

function draw() {
  if (!canvasRef.value) return;
  ctx.clearRect(0, 0, canvasRef.value.width, canvasRef.value.height);
  if (state.grid) {
    state.hexes.forEach((hex) => drawHex(hex, state.grid.hexSize));
  }
  drawShips();
}

function resizeCanvas() {
  if (!canvasRef.value) return;
  canvasRef.value.width = window.innerWidth;
  canvasRef.value.height = window.innerHeight;
  computeOffsets();
  draw();
}

function ensureRenderShip(ship) {
  if (!renderShips.has(ship.id)) {
    renderShips.set(ship.id, { x: ship.x, y: ship.y });
  }
}

function queueAnimation(ship) {
  ensureRenderShip(ship);
  const render = renderShips.get(ship.id);
  const nowSec = Date.now() / 1000;
  const hasMotion = ship.start && ship.target && ship.startTime && ship.endTime && ship.endTime > nowSec;

  if (!hasMotion) {
    render.x = ship.x;
    render.y = ship.y;
    shipAnimations.delete(ship.id);
    if (ship.id === state.ship.id) {
      emit('coords-updated', `${ship.x.toFixed(1)}, ${ship.y.toFixed(1)}`);
    }
    draw();
    return;
  }

  const totalDuration = Math.max((ship.endTime - ship.startTime) * 1000, 1);
  const remainingMs = Math.max((ship.endTime - nowSec) * 1000, 0);
  const elapsedRatio = Math.min(Math.max((nowSec - ship.startTime) / (ship.endTime - ship.startTime), 0), 1);
  const currentX = ship.start.x + (ship.target.x - ship.start.x) * elapsedRatio;
  const currentY = ship.start.y + (ship.target.y - ship.start.y) * elapsedRatio;
  render.x = currentX;
  render.y = currentY;

  shipAnimations.set(ship.id, {
    fromX: currentX,
    fromY: currentY,
    toX: ship.target.x,
    toY: ship.target.y,
    startTime: null,
    duration: Math.max(remainingMs, 1),
  });
  startAnimationLoop();
  draw();
}

function pruneRenderShips(currentShips) {
  const nextIds = new Set(currentShips.map((ship) => ship.id));
  renderShips.forEach((_, id) => {
    if (!nextIds.has(id)) {
      renderShips.delete(id);
      shipAnimations.delete(id);
    }
  });
}

function applyFleetUpdate(fleet) {
  if (!Array.isArray(fleet)) {
    return;
  }

  state.ships = fleet;
  pruneRenderShips(fleet);

  fleet.forEach((ship) => {
    queueAnimation(ship);
    if (ship.id === state.ship.id) {
      state.ship = { ...state.ship, ...ship };
      if (ship.sector) {
        emit('sector-updated', ship.sector);
      }
    }
  });

  draw();
}

function applySyncPayload(data) {
  if (!data) return;
  if (!playerId.value || data.assignedNewId) {
    playerId.value = data.playerId;
    if (playerId.value) {
      localStorage.setItem(PLAYER_STORAGE_KEY, playerId.value);
    }
  }
  emit('player-id', playerId.value);
  state.grid = data.grid;
  state.shipSpeed = data.movement?.speed ?? state.shipSpeed;
  state.ship = {
    ...data.ship,
    radius: SHIP_RADIUS,
    label: '🚢',
    id: data.ship.id || playerId.value,
  };
  buildGrid(data.grid);
  computeOffsets();
  applyFleetUpdate(Array.isArray(data.ships) ? data.ships : []);
  if (data.currentSector) {
    emit('sector-updated', data.currentSector);
  }
  emit('status', '');
}

function handleWsMessage(event) {
  if (!event.data) return;
  let payload;
  try {
    payload = JSON.parse(event.data);
  } catch (error) {
    return;
  }
  switch (payload?.type) {
    case 'fleet:update':
      applyFleetUpdate(payload.ships || []);
      break;
    case 'sync':
      applySyncPayload(payload);
      break;
    case 'sector:update':
      if (payload.playerId === playerId.value && payload.sector) {
        state.ship.sector = payload.sector;
        emit('sector-updated', payload.sector);
      }
      break;
    case 'move:queued':
      emit('status', 'Move queued');
      break;
    case 'error':
      emit('status', payload.message || 'Server error');
      break;
    default:
      break;
  }
}

function scheduleReconnect(reason = 'Realtime channel disconnected') {
  if (reconnectTimeout) {
    return;
  }
  reconnectAttempts = Math.min(reconnectAttempts + 1, 6);
  const delay = Math.min(1000 * (2 ** reconnectAttempts), 10000);
  emit('status', `${reason}. Retrying in ${Math.round(delay / 1000)}s`);
  reconnectTimeout = setTimeout(() => {
    reconnectTimeout = null;
    connectWebSocket();
  }, delay);
  pendingResync = true;
}

function cleanupWebSocket() {
  if (socket) {
    socket.onopen = null;
    socket.onmessage = null;
    socket.onerror = null;
    socket.onclose = null;
    try {
      socket.close();
    } catch (error) {
      // ignore
    }
    socket = null;
  }
  if (reconnectTimeout) {
    clearTimeout(reconnectTimeout);
    reconnectTimeout = null;
  }
}

function sendMessage(payload) {
  const data = JSON.stringify(payload);
  if (socket && socket.readyState === WebSocket.OPEN) {
    socket.send(data);
  } else {
    messageQueue.push(data);
  }
}

function connectWebSocket() {
  cleanupWebSocket();
  try {
    socket = new WebSocket(props.wsBase);
  } catch (error) {
    scheduleReconnect('Realtime connection failed');
    return;
  }
  socket.onopen = () => {
    reconnectAttempts = 0;
    emit('status', 'Connected to realtime channel');
    while (messageQueue.length > 0) {
      socket.send(messageQueue.shift());
    }
    if (pendingResync || !state.grid) {
      requestSync();
      pendingResync = false;
    }
  };
  socket.onmessage = handleWsMessage;
  socket.onerror = () => {
    if (socket) {
      socket.close();
    }
  };
  socket.onclose = () => {
    socket = null;
    scheduleReconnect('Realtime channel closed');
  };
}

function requestSync(forceNewId = false) {
  const payload = { type: 'sync' };
  if (!forceNewId && playerId.value) {
    payload.playerId = playerId.value;
  }
  sendMessage(payload);
}

function animationStep(timestamp) {
  let active = false;
  shipAnimations.forEach((anim, id) => {
    if (anim.startTime === null) anim.startTime = timestamp;
    const progress = Math.min((timestamp - anim.startTime) / anim.duration, 1);
    const render = renderShips.get(id);
    if (!render) return;
    render.x = anim.fromX + (anim.toX - anim.fromX) * progress;
    render.y = anim.fromY + (anim.toY - anim.fromY) * progress;
    if (id === state.ship.id) {
      emit('coords-updated', `${render.x.toFixed(1)}, ${render.y.toFixed(1)}`);
    }
    if (progress >= 1) {
      render.x = anim.toX;
      render.y = anim.toY;
      shipAnimations.delete(id);
    } else {
      active = true;
    }
  });
  draw();
  if (active) {
    animationFrame = requestAnimationFrame(animationStep);
  } else {
    animationFrame = null;
  }
}

function startAnimationLoop() {
  if (animationFrame === null) {
    animationFrame = requestAnimationFrame(animationStep);
  }
}

async function enqueueMove(worldX, worldY) {
  if (!playerId.value) {
    emit('status', 'Awaiting synchronization...');
    pendingResync = true;
    connectWebSocket();
    return;
  }
  emit('status', 'Queuing move...');
  sendMessage({
    type: 'move',
    playerId: playerId.value,
    x: worldX,
    y: worldY,
  });
}

function toWorldCoordinates(clientX, clientY) {
  const rect = canvasRef.value.getBoundingClientRect();
  const screenX = clientX - rect.left;
  const screenY = clientY - rect.top;
  return {
    worldX: screenX - state.offsets.x,
    worldY: screenY - state.offsets.y,
  };
}

function onClick(event) {
  if (!state.grid) return;
  const { worldX, worldY } = toWorldCoordinates(event.clientX, event.clientY);
  enqueueMove(worldX, worldY);
}

onMounted(() => {
  const canvas = canvasRef.value;
  ctx = canvas.getContext('2d');
  canvas.addEventListener('click', onClick);
  window.addEventListener('resize', resizeCanvas);
  resizeCanvas();
  connectWebSocket();
});

onBeforeUnmount(() => {
  if (canvasRef.value) {
    canvasRef.value.removeEventListener('click', onClick);
  }
  window.removeEventListener('resize', resizeCanvas);
  cancelAnimationFrame(animationFrame);
  cleanupWebSocket();
});
</script>
