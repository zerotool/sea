<?php

require __DIR__ . '/../vendor/autoload.php';

use Clue\React\Redis\Factory as RedisFactory;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory as LoopFactory;
use React\Socket\SocketServer;
use Sea\Config;
use Sea\GridService;
use Sea\KafkaProducer;
use Sea\Repository\ShipRepository;
use Sea\Service\EventPublisher;
use Sea\Service\ShipService;
use Sea\Websocket\ShipUpdateServer;

$loop = LoopFactory::create();
$hexes = GridService::buildGrid();
$repository = new ShipRepository($hexes);
$publisher = new EventPublisher(Config::wsChannel());
$shipService = new ShipService($repository, $publisher, $hexes);
$producer = new KafkaProducer();
$wsComponent = new ShipUpdateServer($shipService, $producer);

$redisUri = sprintf('redis://%s:%d', getenv('REDIS_HOST') ?: 'redis', (int)(getenv('REDIS_PORT') ?: 6379));
$redisFactory = new RedisFactory($loop);
$redisClient = $redisFactory->createLazyClient($redisUri);
$redisClient->subscribe(Config::wsChannel());
$redisClient->on('message', function ($channel, $payload) use ($wsComponent) {
    if ($channel === Config::wsChannel()) {
        $wsComponent->broadcast($payload);
    }
});
$redisClient->on('error', function (\Throwable $error) {
    fwrite(STDERR, sprintf("[ws] Redis connection error: %s\n", $error->getMessage()));
});

$socket = new SocketServer('0.0.0.0:8083', [], $loop);
$server = new IoServer(new HttpServer(new WsServer($wsComponent)), $socket, $loop);

$loop->run();
