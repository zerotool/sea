<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sea\GridService;
use Sea\HexMath;

class HexMathTest extends TestCase
{
    public function testHexLineProducesDeterministicPath(): void
    {
        $grid = GridService::buildGrid();
        $byLabel = [];
        $byCoords = [];
        foreach ($grid as $hex) {
            $byLabel[$hex['label']] = [$hex['q'], $hex['r']];
            $byCoords[$hex['q'] . ',' . $hex['r']] = $hex['label'];
        }
        $start = $byLabel['C-2'];
        $end = $byLabel['A-5'];
        $line = HexMath::hexLine($start[0], $start[1], $end[0], $end[1]);
        $labels = array_map(fn ($coords) => $byCoords[$coords['q'] . ',' . $coords['r']] ?? null, $line);
        $this->assertSame(['C-2', 'B-3', 'B-4', 'A-5'], array_values(array_filter($labels)));
    }
}
