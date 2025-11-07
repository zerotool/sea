<?php

namespace Sea\Service;

use Sea\Repository\ShipRepository;

class FleetBroadcaster
{
    private float $intervalSeconds;
    private float $lastBroadcast;
    /** @var callable */
    private $now;
    /** @var array<string, string> */
    private array $lastSectors = [];

    public function __construct(
        private readonly ShipRepository $repository,
        private readonly EventPublisher $publisher,
        int $intervalMs,
        ?callable $nowProvider = null
    ) {
        $this->intervalSeconds = max($intervalMs, 10) / 1000.0;
        $this->lastBroadcast = 0.0;
        $this->now = $nowProvider ?: static fn () => microtime(true);
    }

    public function tick(): void
    {
        $current = ($this->now)();
        if ($current - $this->lastBroadcast < $this->intervalSeconds) {
            return;
        }
        $fleet = $this->repository->getShips();
        $this->publisher->broadcastFleet($fleet);
        $this->broadcastSectorChanges($fleet);
        $this->lastBroadcast = $current;
        $this->pruneMissingShips($fleet);
    }

    private function broadcastSectorChanges(array $fleet): void
    {
        foreach ($fleet as $ship) {
            $id = $ship['id'];
            $currentSector = $ship['sector'] ?? null;
            if ($currentSector === null) {
                continue;
            }
            if (array_key_exists($id, $this->lastSectors) && $this->lastSectors[$id] !== $currentSector) {
                $this->publisher->broadcastSectorChange($id, $currentSector);
            }
            $this->lastSectors[$id] = $currentSector;
        }
    }

    private function pruneMissingShips(array $fleet): void
    {
        $ids = [];
        foreach ($fleet as $ship) {
            $ids[$ship['id']] = true;
        }
        foreach (array_keys($this->lastSectors) as $id) {
            if (!isset($ids[$id])) {
                unset($this->lastSectors[$id]);
            }
        }
    }
}
