<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sea\Service\FleetBroadcaster;
use Sea\Repository\ShipRepository;
use Sea\Service\EventPublisher;

class FleetBroadcasterTest extends TestCase
{
    public function testBroadcastsAtInterval(): void
    {
        $repo = $this->createMock(ShipRepository::class);
        $publisher = $this->createMock(EventPublisher::class);

        $repo->method('getShips')->willReturnOnConsecutiveCalls(
            [['id' => 's1', 'sector' => 'A-1']],
            [['id' => 's1', 'sector' => 'A-2']],
            [['id' => 's1', 'sector' => 'A-3']]
        );
        $publisher->expects($this->exactly(3))->method('broadcastFleet');
        $sectorCalls = [];
        $publisher->expects($this->exactly(2))
            ->method('broadcastSectorChange')
            ->willReturnCallback(function ($playerId, $sector) use (&$sectorCalls) {
                $sectorCalls[] = [$playerId, $sector];
            });

        $ticks = [0.20, 0.40, 0.60];
        $nowProvider = function () use (&$ticks) {
            return array_shift($ticks) ?? end($ticks);
        };

        $broadcaster = new FleetBroadcaster($repo, $publisher, 100, $nowProvider);
        $broadcaster->tick(); // 0.0 -> should broadcast
        $broadcaster->tick(); // 0.20 -> over interval -> broadcast
        $broadcaster->tick(); // 0.40 -> over interval -> broadcast

        $this->assertSame([['s1', 'A-2'], ['s1', 'A-3']], $sectorCalls);
    }
}
