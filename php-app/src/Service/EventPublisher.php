<?php

namespace Sea\Service;

use Redis;
use Sea\Config;

class EventPublisher
{
    private Redis $redis;
    private string $channel;

    public function __construct(?string $channel = null)
    {
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $this->redis = new Redis();
        $this->redis->connect($host, $port);
        $this->channel = $channel ?: Config::wsChannel();
    }

    public function broadcastFleet(array $fleet): void
    {
        $payload = json_encode([
            'type' => 'fleet:update',
            'ships' => $fleet,
        ]);
        if ($payload !== false) {
            $this->redis->publish($this->channel, $payload);
        }
    }

    public function broadcastSectorChange(string $playerId, string $sector): void
    {
        $payload = json_encode([
            'type' => 'sector:update',
            'playerId' => $playerId,
            'sector' => $sector,
        ]);
        if ($payload !== false) {
            $this->redis->publish($this->channel, $payload);
        }
    }
}
