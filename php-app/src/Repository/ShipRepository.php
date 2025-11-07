<?php

namespace Sea\Repository;

use Redis;
use Sea\Config;
use Sea\GridService;
use Sea\HexMath;

class ShipRepository
{
    private Redis $redis;
    /** @var array<int, array<string, mixed>> */
    private array $hexes;
    /** @var array<string, array<string, mixed>> */
    private array $hexesByLabel = [];
    /** @var array<string, array<string, mixed>> */
    private array $hexesByCoords = [];

    public function __construct(array $hexes)
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $this->redis = new Redis();
        $this->redis->connect($host, $port);
        $this->hexes = $hexes;
        foreach ($hexes as $hex) {
            $this->hexesByLabel[$hex['label']] = $hex;
            $this->hexesByCoords[$hex['q'] . ',' . $hex['r']] = $hex;
        }
    }

    public function getSnapshot(): array
    {
        $data = $this->redis->get(Config::redisKey());
        if ($data) {
            $decoded = json_decode($data, true);
            if (is_array($decoded) && isset($decoded['ships'])) {
                return $decoded;
            }
        }
        $snapshot = ['ships' => []];
        $this->saveSnapshot($snapshot);
        return $snapshot;
    }

    public function ensureShip(string $playerId): array
    {
        $snapshot = $this->pruneAndProject($this->getSnapshot());
        if (!isset($snapshot['ships'][$playerId])) {
            $hex = $this->hexes[array_rand($this->hexes)];
            $snapshot['ships'][$playerId] = $this->newShipState(
                $playerId,
                $hex['center'][0],
                $hex['center'][1],
                $hex['label']
            );
            $this->saveSnapshot($snapshot);
        }
        return $snapshot['ships'][$playerId];
    }

    public function ensureShipPublic(string $playerId): array
    {
        return $this->publicShip($this->ensureShip($playerId));
    }

    public function getShipState(string $playerId): ?array
    {
        $snapshot = $this->pruneAndProject($this->getSnapshot());
        if (!isset($snapshot['ships'][$playerId])) {
            return null;
        }
        $this->saveSnapshot($snapshot);
        return $snapshot['ships'][$playerId];
    }

    public function getShips(): array
    {
        $snapshot = $this->pruneAndProject($this->getSnapshot());
        $this->saveSnapshot($snapshot);
        return array_values(array_map(fn ($ship) => $this->publicShip($ship), $snapshot['ships']));
    }

    public function saveMovement(
        string $playerId,
        float $startX,
        float $startY,
        string $startSector,
        float $targetX,
        float $targetY,
        string $targetSector
    ): void
    {
        $snapshot = $this->pruneAndProject($this->getSnapshot(), false);
        $distance = hypot($targetX - $startX, $targetY - $startY);
        if ($distance <= 0.0) {
            return;
        }
        $speed = Config::shipSpeed();
        $duration = max($distance / $speed, 0.001);
        $startTime = microtime(true);
        $ship = $snapshot['ships'][$playerId] ?? $this->newShipState($playerId, $startX, $startY, $startSector);
        $ship['startX'] = $startX;
        $ship['startY'] = $startY;
        $ship['x'] = $startX;
        $ship['y'] = $startY;
        $ship['targetX'] = $targetX;
        $ship['targetY'] = $targetY;
        $ship['startTime'] = $startTime;
        $ship['endTime'] = $startTime + $duration;
        $ship['sector'] = $ship['sector'] ?? $startSector;
        $ship['targetSector'] = $targetSector;
        $pathData = $this->buildPathData($startSector, $targetSector, $distance);
        $dirX = ($targetX - $startX) / $distance;
        $dirY = ($targetY - $startY) / $distance;
        $ship['movement'] = [
            'dirX' => $dirX,
            'dirY' => $dirY,
            'speed' => $speed,
            'remaining' => $distance,
            'traveled' => 0.0,
            'thresholds' => $pathData['thresholds'],
            'labels' => $pathData['labels'],
            'nextIndex' => 1,
        ];
        $ship['updatedAt'] = time();
        $snapshot['ships'][$playerId] = $ship;
        $this->saveSnapshot($snapshot);
    }

    public function isShipMoving(string $playerId): bool
    {
        $snapshot = $this->pruneAndProject($this->getSnapshot(), true);
        $ship = $snapshot['ships'][$playerId] ?? null;
        if (!$ship) {
            return false;
        }
        $moving = isset($ship['targetX'], $ship['targetY'], $ship['endTime'])
            && $ship['endTime'] > microtime(true);
        if ($moving) {
            $this->saveSnapshot($snapshot);
        }
        return $moving;
    }

    public function markPendingMove(string $playerId): bool
    {
        return (bool) $this->redis->set(
            $this->pendingKey($playerId),
            '1',
            ['nx', 'ex' => Config::pendingMoveTtlSeconds()]
        );
    }

    public function clearPendingMove(string $playerId): void
    {
        $this->redis->del($this->pendingKey($playerId));
    }

    public function updateShipImmediate(string $playerId, float $x, float $y, string $sector): void
    {
        $snapshot = $this->pruneAndProject($this->getSnapshot(), false);
        $snapshot['ships'][$playerId] = $this->newShipState($playerId, $x, $y, $sector);
        $this->saveSnapshot($snapshot);
    }

    private function pruneAndProject(array $snapshot, bool $persist = true): array
    {
        $changed = false;
        $now = time();
        foreach ($snapshot['ships'] as $id => &$ship) {
            $last = $ship['updatedAt'] ?? 0;
            if ($now - $last > Config::sessionTtlSeconds()) {
                unset($snapshot['ships'][$id]);
                $changed = true;
            }
        }
        unset($ship);
        if ($changed && $persist) {
            $this->saveSnapshot($snapshot);
        }
        return $snapshot;
    }

    public function advanceShips(float $deltaSeconds, callable $sectorCallback): void
    {
        if ($deltaSeconds <= 0) {
            return;
        }
        $snapshot = $this->pruneAndProject($this->getSnapshot(), false);
        $changed = false;
        foreach ($snapshot['ships'] as &$ship) {
            if (!$this->advanceMovement($ship, $deltaSeconds, $sectorCallback)) {
                continue;
            }
            $changed = true;
        }
        unset($ship);
        if ($changed) {
            $this->saveSnapshot($snapshot);
        }
    }

    private function sectorAt(float $x, float $y): ?string
    {
        $hex = GridService::findHexAt($this->hexes, $x, $y);
        return $hex['label'] ?? null;
    }

    private function travelDuration(float $startX, float $startY, float $targetX, float $targetY): float
    {
        $distance = hypot($targetX - $startX, $targetY - $startY);
        if ($distance === 0) {
            return 0.0;
        }
        return $distance / Config::shipSpeed();
    }

    private function segmentDuration(array $start, array $end): float
    {
        $distance = hypot($end['x'] - $start['x'], $end['y'] - $start['y']);
        if ($distance === 0) {
            return 0.0001;
        }
        return max($distance / Config::shipSpeed(), 0.0001);
    }

    private function saveSnapshot(array $snapshot): void
    {
        $this->redis->set(Config::redisKey(), json_encode($snapshot));
    }

    private function newShipState(string $playerId, float $x, float $y, string $sector): array
    {
        return [
            'id' => $playerId,
            'x' => $x,
            'y' => $y,
            'sector' => $sector,
            'updatedAt' => time(),
        ];
    }

    private function publicShip(array $ship): array
    {
        $base = [
            'id' => $ship['id'],
            'x' => (float)$ship['x'],
            'y' => (float)$ship['y'],
            'sector' => $ship['sector'],
        ];
        if (isset($ship['targetX'], $ship['targetY'], $ship['startTime'], $ship['endTime'])) {
            $base['start'] = ['x' => $ship['startX'], 'y' => $ship['startY']];
            $base['target'] = ['x' => $ship['targetX'], 'y' => $ship['targetY']];
            $base['startTime'] = $ship['startTime'];
            $base['endTime'] = $ship['endTime'];
            if (isset($ship['targetSector'])) {
                $base['targetSector'] = $ship['targetSector'];
            }
        }
        return $base;
    }

    private function pendingKey(string $playerId): string
    {
        return 'ship_pending:' . $playerId;
    }

    private function getHexByLabel(string $label): ?array
    {
        return $this->hexesByLabel[$label] ?? null;
    }

    private function getHexByCoords(int $q, int $r): ?array
    {
        return $this->hexesByCoords[$q . ',' . $r] ?? null;
    }

    private function buildPathData(string $startLabel, string $targetLabel, float $distance): array
    {
        $startHex = $this->getHexByLabel($startLabel);
        $targetHex = $this->getHexByLabel($targetLabel);
        if (!$startHex || !$targetHex) {
            return [
                'labels' => [$startLabel, $targetLabel],
                'thresholds' => [0.0, $distance],
            ];
        }
        $line = HexMath::hexLine($startHex['q'], $startHex['r'], $targetHex['q'], $targetHex['r']);
        $steps = max(count($line) - 1, 1);
        $labels = [];
        $thresholds = [];
        foreach ($line as $index => $coords) {
            $hex = $this->getHexByCoords($coords['q'], $coords['r']);
            $labels[] = $hex['label'] ?? $startLabel;
            $thresholds[] = ($distance * $index) / $steps;
        }
        if (empty($labels)) {
            $labels = [$startLabel, $targetLabel];
            $thresholds = [0.0, $distance];
        } elseif ($labels[array_key_last($labels)] !== $targetLabel) {
            $labels[array_key_last($labels)] = $targetLabel;
            $thresholds[array_key_last($thresholds)] = $distance;
        }
        return ['labels' => $labels, 'thresholds' => $thresholds];
    }

    private function advanceMovement(array &$ship, float $deltaSeconds, callable $sectorCallback): bool
    {
        if (!isset($ship['movement'])) {
            return false;
        }
        $movement = &$ship['movement'];
        $speed = $movement['speed'] ?? Config::shipSpeed();
        $step = min($speed * $deltaSeconds, $movement['remaining'] ?? 0.0);
        if ($step <= 0) {
            return false;
        }
        $ship['x'] += ($movement['dirX'] ?? 0.0) * $step;
        $ship['y'] += ($movement['dirY'] ?? 0.0) * $step;
        $movement['remaining'] -= $step;
        $movement['traveled'] += $step;
        $ship['updatedAt'] = time();
        $this->updateMovementSector($ship, $sectorCallback);
        if ($movement['remaining'] <= 0.0001) {
            $ship['x'] = $ship['targetX'] ?? $ship['x'];
            $ship['y'] = $ship['targetY'] ?? $ship['y'];
            $ship['sector'] = $movement['labels'][array_key_last($movement['labels'])] ?? $ship['sector'];
            unset($ship['movement']);
            unset(
                $ship['targetX'],
                $ship['targetY'],
                $ship['startX'],
                $ship['startY'],
                $ship['startTime'],
                $ship['endTime'],
                $ship['targetSector']
            );
        }
        return true;
    }

    private function updateMovementSector(array &$ship, callable $sectorCallback): void
    {
        $movement = &$ship['movement'];
        $thresholds = $movement['thresholds'] ?? [];
        $labels = $movement['labels'] ?? [];
        $index = $movement['nextIndex'] ?? 1;
        $traveled = $movement['traveled'] ?? 0.0;
        $count = count($thresholds);
        while ($index < $count && $traveled + 1e-6 >= $thresholds[$index]) {
            $sector = $labels[$index] ?? null;
            if ($sector && $ship['sector'] !== $sector) {
                $ship['sector'] = $sector;
                $sectorCallback($ship['id'], $sector);
            }
            $index++;
        }
        $movement['nextIndex'] = $index;
    }

}
