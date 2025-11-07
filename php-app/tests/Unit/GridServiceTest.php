<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sea\Config;
use Sea\GridService;

class GridServiceTest extends TestCase
{
    public function testBuildGridMatchesConfig(): void
    {
        $grid = GridService::buildGrid();
        $this->assertCount(count(Config::rowLabels()) * Config::gridCols(), $grid);
        $this->assertSame('A-1', $grid[0]['label']);
    }

    public function testGridPayloadContainsLabels(): void
    {
        $payload = GridService::gridPayload();
        $this->assertSame(Config::gridCols(), $payload['cols']);
        $this->assertSame(Config::hexSize(), $payload['hexSize']);
        $this->assertSame('B-2', $payload['labels'][1][1]);
    }

    public function testFindHexAtReturnsCorrectLabel(): void
    {
        $hexes = GridService::buildGrid();
        $sample = $hexes[5]; // some deterministic hex
        $center = $sample['center'];
        $found = GridService::findHexAt($hexes, $center[0], $center[1]);
        $this->assertNotNull($found);
        $this->assertSame($sample['label'], $found['label']);
    }
}
