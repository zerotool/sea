<?php

namespace Sea\Controller;

use InvalidArgumentException;
use Sea\Config;
use Sea\Exception\ShipBusyException;
use Sea\GridService;
use Sea\KafkaProducer;
use Sea\Service\ShipService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ApiController
{
    public function __construct(
        private readonly ShipService $shipService,
        private readonly KafkaProducer $producer
    ) {
    }

    public function health(Request $request): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    public function state(Request $request): JsonResponse
    {
        $playerId = $this->resolvePlayerId($request);
        $assignedNewId = false;
        if (!$playerId) {
            $playerId = bin2hex(random_bytes(16));
            $assignedNewId = true;
        }

        $snapshot = $this->shipService->getStateSnapshot($playerId);
        $ship = $snapshot['ship'];
        $fleet = $snapshot['fleet'];

        $response = [
            'playerId' => $playerId,
            'grid' => GridService::gridPayload(),
            'ship' => $ship,
            'ships' => $fleet,
            'currentSector' => $ship['sector'],
            'movement' => ['speed' => Config::shipSpeed()],
        ];

        if ($assignedNewId) {
            $response['assignedNewId'] = true;
        }

        return new JsonResponse($response);
    }

    public function move(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $playerId = $payload['playerId'] ?? $this->resolvePlayerId($request);
        if (!$playerId) {
            return $this->error('playerId is required', 400);
        }

        if (!isset($payload['x'], $payload['y']) || !is_numeric($payload['x']) || !is_numeric($payload['y'])) {
            return $this->error('Numeric x and y are required', 400);
        }

        $x = (float)$payload['x'];
        $y = (float)$payload['y'];

        try {
            $this->shipService->requestMove($playerId, $x, $y);
        } catch (ShipBusyException $busy) {
            return $this->error($busy->getMessage(), 409);
        } catch (InvalidArgumentException $invalid) {
            return $this->error($invalid->getMessage(), 400);
        }

        $this->producer->enqueueMove($playerId, $x, $y);
        return new JsonResponse(['queued' => true]);
    }

    private function resolvePlayerId(Request $request): ?string
    {
        $header = $request->headers->get('X-Player-ID');
        if ($header) {
            return (string)$header;
        }

        $query = $request->query->get('playerId');
        if ($query) {
            return (string)$query;
        }

        $payload = $this->decodeJson($request);
        if (isset($payload['playerId'])) {
            return (string)$payload['playerId'];
        }

        return null;
    }

    private function decodeJson(Request $request): array
    {
        $content = trim((string)$request->getContent());
        if ($content === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }
}
