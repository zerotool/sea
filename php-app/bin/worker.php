<?php

require __DIR__ . '/../vendor/autoload.php';

use Sea\Config;
use Sea\GridService;
use Sea\Repository\ShipRepository;
use Sea\Service\EventPublisher;
use Sea\Service\FleetBroadcaster;
use Sea\Service\ShipService;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;

$conf = new Conf();
$brokers = getenv('KAFKA_BROKERS') ?: 'kafka:9092';
$conf->set('group.id', getenv('KAFKA_CONSUMER_GROUP') ?: 'sim-worker-group');
$conf->set('metadata.broker.list', $brokers);
$conf->set('bootstrap.servers', $brokers);
$conf->set('enable.auto.commit', 'true');
$conf->set('auto.offset.reset', 'earliest');

waitForKafka($brokers);

$consumer = new KafkaConsumer($conf);
$consumer->subscribe([Config::kafkaTopic()]);

$hexes = GridService::buildGrid();
$repository = new ShipRepository($hexes);
$publisher = new EventPublisher();
$shipService = new ShipService($repository, $publisher, $hexes);
$broadcaster = new FleetBroadcaster($repository, $publisher, Config::fleetBroadcastIntervalMs());
$lastTick = microtime(true);

fwrite(STDOUT, "Worker listening for move intents...\n");

while (true) {
    $message = $consumer->consume(100);
    $now = microtime(true);
    $delta = $now - $lastTick;
    if ($delta > 0) {
        $repository->advanceShips($delta, static function (string $playerId, string $sector) use ($publisher) {
            $publisher->broadcastSectorChange($playerId, $sector);
        });
        $lastTick = $now;
    }
    if ($message === null) {
        $broadcaster->tick();
        continue;
    }

    if ($message->err) {
        if ($message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            continue;
        }
        fwrite(STDERR, "Kafka error: {$message->errstr()}\n");
        continue;
    }

    $payload = json_decode($message->payload, true);
    if (!is_array($payload) || !isset($payload['x'], $payload['y'], $payload['playerId'])) {
        continue;
    }

    $x = (float)$payload['x'];
    $y = (float)$payload['y'];
    $playerId = (string)$payload['playerId'];
    try {
        $shipService->processIntent($playerId, $x, $y);
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf('[worker] failed processing intent for %s: %s' . PHP_EOL, $playerId, $e->getMessage()));
        $repository->clearPendingMove($playerId);
    }
    $broadcaster->tick();
}

function waitForKafka(string $brokers, int $timeout = 60): void
{
    $end = time() + $timeout;
    $targets = array_map('trim', explode(',', $brokers));
    while (time() < $end) {
        foreach ($targets as $target) {
            [$host, $port] = array_pad(explode(':', $target, 2), 2, '9092');
            $connection = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);
            if ($connection) {
                fclose($connection);
                return;
            }
        }
        fwrite(STDOUT, "Waiting for Kafka brokers: {$brokers}\n");
        sleep(1);
    }
    fwrite(STDERR, "Kafka brokers {$brokers} still unreachable; continuing with retries.\n");
}
