<?php

namespace Sea;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class Config
{
    private const DEFAULTS = [
        'grid' => [
            'hexSize' => 80.0,
            'rowLabels' => ['A', 'B', 'C', 'D'],
            'cols' => 6,
        ],
        'movement' => [
            'shipSpeed' => 220.0,
            'sessionTtlSeconds' => 300,
            'pendingMoveTtlSeconds' => 30,
        ],
        'persistence' => [
            'redisKey' => 'ship_state_v2',
            'wsChannel' => 'ship_updates',
        ],
        'kafka' => [
            'topic' => 'movement-intents',
        ],
        'broadcast' => [
            'fleetIntervalMs' => 150,
        ],
    ];

    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $path = getenv('SEA_CONFIG_PATH') ?: __DIR__ . '/../config/settings.yaml';
        if (!file_exists($path)) {
            self::$cache = self::DEFAULTS;
            return self::$cache;
        }
        $parsed = Yaml::parseFile($path);
        if (!is_array($parsed)) {
            throw new RuntimeException('Invalid YAML configuration.');
        }
        self::$cache = array_replace_recursive(self::DEFAULTS, $parsed);
        return self::$cache;
    }

    public static function hexSize(): float
    {
        return (float) self::load()['grid']['hexSize'];
    }

    /**
     * @return string[]
     */
    public static function rowLabels(): array
    {
        return array_values(self::load()['grid']['rowLabels']);
    }

    public static function gridCols(): int
    {
        return (int) self::load()['grid']['cols'];
    }

    public static function shipSpeed(): float
    {
        return (float) self::load()['movement']['shipSpeed'];
    }

    public static function sessionTtlSeconds(): int
    {
        return (int) self::load()['movement']['sessionTtlSeconds'];
    }

    public static function pendingMoveTtlSeconds(): int
    {
        return (int) self::load()['movement']['pendingMoveTtlSeconds'];
    }

    public static function redisKey(): string
    {
        return (string) self::load()['persistence']['redisKey'];
    }

    public static function wsChannel(): string
    {
        return (string) self::load()['persistence']['wsChannel'];
    }

    public static function kafkaTopic(): string
    {
        return (string) self::load()['kafka']['topic'];
    }

    public static function fleetBroadcastIntervalMs(): int
    {
        return (int) self::load()['broadcast']['fleetIntervalMs'];
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
