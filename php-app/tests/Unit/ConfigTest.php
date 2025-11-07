<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sea\Config;

class ConfigTest extends TestCase
{
    public function testLoadsDefaultsWhenYamlMissing(): void
    {
        putenv('SEA_CONFIG_PATH=' . __DIR__ . '/missing.yaml');
        $this->assertSame(80.0, Config::hexSize());
        $this->assertSame(['A', 'B', 'C', 'D'], Config::rowLabels());
        $this->assertSame(6, Config::gridCols());
        $this->assertSame('ship_state_v2', Config::redisKey());
        $this->assertSame('movement-intents', Config::kafkaTopic());
        $this->assertSame(220.0, Config::shipSpeed());
        $this->assertSame(150, Config::fleetBroadcastIntervalMs());
        Config::reset();
        putenv('SEA_CONFIG_PATH');
    }
}
