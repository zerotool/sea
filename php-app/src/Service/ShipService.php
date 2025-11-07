<?php

namespace Sea\Service;

use InvalidArgumentException;
use Sea\Exception\ShipBusyException;
use Sea\GridService;
use Sea\Repository\ShipRepository;

class ShipService
{
    public function __construct(
        private readonly ShipRepository $repository,
        private readonly EventPublisher $publisher,
        private readonly array $hexes
    ) {
    }

    /**
     * @return array{ship: array, fleet: array}
     */
    public function getStateSnapshot(string $playerId): array
    {
        $ship = $this->repository->ensureShipPublic($playerId);
        $fleet = $this->repository->getShips();
        return ['ship' => $ship, 'fleet' => $fleet];
    }

    public function requestMove(string $playerId, float $x, float $y): void
    {
        $targetHex = GridService::findHexAt($this->hexes, $x, $y);
        if (!$targetHex) {
            throw new InvalidArgumentException('Target is outside of the known sea sectors.');
        }

        $ship = $this->repository->getShipState($playerId) ?? $this->repository->ensureShip($playerId);

        $distance = hypot($ship['x'] - $x, $ship['y'] - $y);
        if ($distance < 1.0) {
            throw new InvalidArgumentException('Ship is already at that position.');
        }

        if ($this->repository->isShipMoving($playerId)) {
            throw new ShipBusyException('Ship is currently travelling.');
        }

        if (!$this->repository->markPendingMove($playerId)) {
            throw new ShipBusyException('Ship already has a queued movement.');
        }
    }

    public function processIntent(string $playerId, float $x, float $y): void
    {
        $targetHex = GridService::findHexAt($this->hexes, $x, $y);
        if (!$targetHex) {
            $this->repository->clearPendingMove($playerId);
            return;
        }

        $ship = $this->repository->getShipState($playerId) ?? $this->repository->ensureShip($playerId);
        $this->repository->saveMovement(
            $playerId,
            $ship['x'],
            $ship['y'],
            $ship['sector'],
            $x,
            $y,
            $targetHex['label']
        );
        $this->repository->clearPendingMove($playerId);
    }
}
