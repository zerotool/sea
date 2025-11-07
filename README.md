# Sea Battle Prototype (Vue + PHP + Kafka + WebSockets)

A production-style slice of a naval strategy MMO:

- **Authoritative backend (PHP 8.3)** – Symfony HTTP components + service/repository/DTO layers keep every rule server-side. Movement requests are validated, deduplicated, and turned into intents on Kafka.
- **Simulation pipeline** – Kafka (`movement-intents`) serializes player actions, the worker processes them sequentially, persists the fleet state in Redis, and broadcasts updates over Redis pub/sub.
- **Realtime fan-out** – A dedicated WebSocket service (Ratchet + ReactPHP) streams Redis updates to every browser tab. After a single bootstrap request the Vue client listens exclusively to this stream; the simulation worker now advances every active movement every tick and emits `fleet:update` plus `sector:update` events the instant a ship crosses into a new hex.
- **Vue 3 canvas client** – One tab equals one ship. The canvas renders a 4×6 (24-sector) hex grid, animates motion using server-provided start/end timestamps, and falls back to light polling for resyncs.

## Docker services
```
docker-compose.yml
├─ zookeeper      # Required by Kafka
├─ kafka          # Event bus for move intents
├─ redis          # Fleet state + realtime pub/sub
├─ php-api        # Symfony-style HTTP API (port 4000)
├─ php-worker     # Kafka consumer that advances the simulation
├─ ws-server      # Ratchet WebSocket bridge (port 8083)
└─ frontend       # Vue/Vite build served via nginx (port 8082)
```

## Run locally
1. Recreate the stack (fresh build each time to keep PHP/Vue artifacts in sync):
   ```bash
   docker compose down --remove-orphans
   docker compose up -d --build
   ```
2. Visit http://localhost:8082 – each browser tab receives its own `playerId`, ship, and WebSocket session.
3. API + health checks:
   - `curl http://localhost:4000/health`
   - `curl -H "X-Player-ID: <id>" http://localhost:4000/api/state`

Stop everything with `docker compose down`.

## Configuration
- **Server values** – `php-app/config/settings.yaml` centralizes grid size, row labels, ship speed, Redis/Kafka topics, etc. Override the whole file or point `SEA_CONFIG_PATH` to a different YAML at runtime.
- **WS broadcast interval** – adjust `broadcast.fleetIntervalMs` in the same YAML if you want to slow down or speed up how often the worker pushes fleet snapshots to WebSocket clients.
- **Frontend endpoints** – copy `client/.env.example` → `client/.env` (optional) and tweak `VITE_API_BASE` / `VITE_WS_BASE`. The Vue app falls back to `http(s)://<host>:4000` and `ws(s)://<host>:8083/ws` if the env vars are absent, so Docker/default dev stacks work out of the box.

## Developer CLI (bin/sea.sh)
To avoid remembering long docker / test commands, use the helper script:
```
bin/sea.sh start      # docker compose up -d
bin/sea.sh stop       # docker compose down
bin/sea.sh restart    # full restart (down --remove-orphans + up -d)
bin/sea.sh status     # show container status
bin/sea.sh logs api   # tail logs for a service
bin/sea.sh erase      # flush Redis data (resets the sea state)
bin/sea.sh test       # installs deps, runs PHP unit tests + Vitest + Playwright
```
Treat `bin/sea.sh test` as the single regression command to run after each development cycle—it boots the stack (if needed) and executes every suite end-to-end.

## Gameplay rules (MVP)
- Sea grid = 24 hexes (rows `A..D`, columns `1..6`). `GridService` builds the coordinates and `HexMath` handles axial/point math.
- Ships are spawned in random sectors but **never move client-side**; every click becomes an intent, queued via Kafka.
- Only one movement can be in-flight per ship. Requests while travelling respond with HTTP `409`.
- Ship positions are interpolated on the client using authoritative `start`, `target`, `startTime`, `endTime` coming from the worker, so collisions and combat math can later be handled mid-flight.

## API & realtime contracts
| Method | Endpoint       | Description |
| ------ | -------------- | ----------- |
| GET    | `/health`      | Basic liveness probe. |
| GET    | `/api/state`   | Returns `{ playerId, grid, ship, ships, currentSector, movement }`. Accepts `X-Player-ID` or `?playerId=` to resume an existing ship. Creates a new player ID if none is supplied. |
| POST   | `/api/move`    | Body `{ playerId, x, y }`. Validates sector bounds, ensures no pending move, enqueues on Kafka, and responds `{ "queued": true }`. |
| WS     | `ws://localhost:8083/ws` | Two-way channel. Clients send `{"type":"sync","playerId":...}` to bootstrap/resync and `{"type":"move","playerId":...,"x":...,"y":...}` to queue movement. Server pushes `sync`, `fleet:update`, `sector:update` (emitted mid-voyage each time a ship crosses to another sector), `move:queued`, and `error` events. |

All controllers live in `php-app/src/Controller/ApiController.php` and respond via Symfony `JsonResponse`, so CORS headers and error codes stay consistent.

## Code layout highlights
```
php-app/
├─ public/index.php                  # Router + RequestContext + ApiController wiring
├─ src/
│   ├─ Controller/ApiController.php  # REST endpoints (health/state/move)
│   ├─ Service/ShipService.php       # Domain rules + validation
│   ├─ Service/EventPublisher.php    # Redis pub/sub publisher
│   ├─ Repository/ShipRepository.php # Redis persistence + interpolation
│   ├─ GridService.php / HexMath.php # Hex grid math helpers
│   └─ KafkaProducer.php             # Kafka producer abstraction
├─ bin/worker.php                    # Kafka consumer → Redis + broadcast
├─ bin/ws-server.php                 # Redis subscriber → WebSocket fan-out
└─ Dockerfile                        # PHP 8.3 CLI + redis/rdkafka extensions

client/
├─ src/App.vue                       # HUD + wires up SeaCanvas
├─ src/components/SeaCanvas.vue      # Canvas renderer + WS client + movement UX
└─ Dockerfile                        # Node builder → nginx runtime
```

## Testing performed
- `bin/sea.sh test` (runs composer/phpunit + `npm run test` → Vitest + Playwright API E2E)
- `docker compose down --remove-orphans`
- `docker compose up -d --build`
- `docker compose ps` (confirmed php-api, php-worker, ws-server, redis, kafka, zookeeper, frontend are healthy)
- `curl http://localhost:4000/api/state` (24-sector grid + auto-assigned `playerId`)
- `curl -X POST http://localhost:4000/api/move -H 'Content-Type: application/json' -d '{"playerId":"...","x":100,"y":100}'` (movement queued + processed)

With the stack running, open two browser tabs on http://localhost:8082 and issue moves in each; ships glide smoothly in both tabs thanks to the server-driven movement timeline plus WebSocket fan-out.
