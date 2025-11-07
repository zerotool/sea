<?php

namespace Sea\Websocket;

use InvalidArgumentException;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use Sea\Config;
use Sea\Exception\ShipBusyException;
use Sea\GridService;
use Sea\KafkaProducer;
use Sea\Service\ShipService;
use SplObjectStorage;

class ShipUpdateServer implements MessageComponentInterface
{
    private SplObjectStorage $clients;

    public function __construct(
        private readonly ShipService $shipService,
        private readonly KafkaProducer $producer
    ) {
        $this->clients = new SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn);
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $payload = json_decode($msg, true);
        if (!is_array($payload) || !isset($payload['type'])) {
            $this->sendError($from, 'Invalid payload');
            return;
        }

        switch ($payload['type']) {
            case 'sync':
            case 'hello':
                $this->handleSync($from, $payload);
                break;
            case 'move':
                $this->handleMove($from, $payload);
                break;
            default:
                $this->sendError($from, 'Unknown message type');
                break;
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->close();
    }

    public function broadcast(string $payload): void
    {
        foreach ($this->clients as $client) {
            $client->send($payload);
        }
    }

    private function handleSync(ConnectionInterface $conn, array $payload): void
    {
        $incomingId = isset($payload['playerId']) && is_string($payload['playerId']) && $payload['playerId'] !== ''
            ? (string)$payload['playerId']
            : null;
        $playerId = $incomingId ?: bin2hex(random_bytes(16));
        $assignedNewId = $incomingId === null;

        $snapshot = $this->shipService->getStateSnapshot($playerId);
        $response = [
            'type' => 'sync',
            'playerId' => $playerId,
            'grid' => GridService::gridPayload(),
            'ship' => $snapshot['ship'],
            'ships' => $snapshot['fleet'],
            'currentSector' => $snapshot['ship']['sector'],
            'movement' => ['speed' => Config::shipSpeed()],
        ];

        if ($assignedNewId) {
            $response['assignedNewId'] = true;
        }

        $conn->send(json_encode($response));
    }

    private function handleMove(ConnectionInterface $conn, array $payload): void
    {
        if (!isset($payload['playerId'], $payload['x'], $payload['y'])) {
            $this->sendError($conn, 'Move payload missing playerId/x/y');
            return;
        }

        $playerId = (string)$payload['playerId'];
        if ($playerId === '') {
            $this->sendError($conn, 'playerId is required');
            return;
        }

        if (!is_numeric($payload['x']) || !is_numeric($payload['y'])) {
            $this->sendError($conn, 'Coordinates must be numeric');
            return;
        }

        $x = (float)$payload['x'];
        $y = (float)$payload['y'];

        try {
            $this->shipService->requestMove($playerId, $x, $y);
            $this->producer->enqueueMove($playerId, $x, $y);
            $conn->send(json_encode(['type' => 'move:queued']));
        } catch (ShipBusyException|InvalidArgumentException $e) {
            $this->sendError($conn, $e->getMessage());
        } catch (\Throwable $e) {
            $this->sendError($conn, 'Server error processing move');
        }
    }

    private function sendError(ConnectionInterface $conn, string $message): void
    {
        $conn->send(json_encode(['type' => 'error', 'message' => $message]));
    }
}
