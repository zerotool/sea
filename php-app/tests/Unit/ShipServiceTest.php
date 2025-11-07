<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sea\Exception\ShipBusyException;
use Sea\GridService;
use Sea\Repository\ShipRepository;
use Sea\Service\EventPublisher;
use Sea\Service\ShipService;

class ShipServiceTest extends TestCase
{
    private array $hexes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hexes = GridService::buildGrid();
    }

    public function testRequestMoveRejectsOutsideGrid(): void
    {
        $repository = $this->createMock(ShipRepository::class);
        $repository->method('getShipState')->willReturn(['x' => 0, 'y' => 0, 'sector' => 'A-1']);
        $repository->method('isShipMoving')->willReturn(false);
        $repository->method('markPendingMove')->willReturn(true);

        $service = new ShipService($repository, $this->createMock(EventPublisher::class), $this->hexes);

        $this->expectException(InvalidArgumentException::class);
        $service->requestMove('p1', -9999, -9999);
    }

    public function testRequestMoveRejectsBusyShip(): void
    {
        $repository = $this->createMock(ShipRepository::class);
        $repository->method('getShipState')->willReturn(['x' => 0, 'y' => 0, 'sector' => 'A-1']);
        $repository->method('isShipMoving')->willReturn(true);

        $service = new ShipService($repository, $this->createMock(EventPublisher::class), $this->hexes);

        $this->expectException(ShipBusyException::class);
        $service->requestMove('p1', 10, 10);
    }

    public function testProcessIntentPersistsMovement(): void
    {
        $repository = $this->createMock(ShipRepository::class);
        $publisher = $this->createMock(EventPublisher::class);
        $target = $this->hexes[0]['center'];

        $repository->method('getShipState')->willReturn(['x' => 0.0, 'y' => 0.0, 'sector' => 'A-1']);
        $repository->expects($this->once())
            ->method('saveMovement')
            ->with('p1', 0.0, 0.0, 'A-1', $target[0], $target[1], 'A-1');
        $repository->expects($this->once())->method('clearPendingMove')->with('p1');
        $publisher->expects($this->never())->method('broadcastFleet');

        $service = new ShipService($repository, $publisher, $this->hexes);
        $service->processIntent('p1', $target[0], $target[1]);
    }
}
