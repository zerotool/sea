<?php

namespace Sea;

use RdKafka\Conf;
use RdKafka\Producer;

class KafkaProducer
{
    private Producer $producer;
    private string $topicName;

    public function __construct()
    {
        $conf = new Conf();
        $conf->set('bootstrap.servers', getenv('KAFKA_BROKERS') ?: 'kafka:9092');
        $this->producer = new Producer($conf);
        $this->topicName = Config::kafkaTopic();
    }

    public function enqueueMove(string $playerId, float $x, float $y): void
    {
        $topic = $this->producer->newTopic($this->topicName);
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode([
            'playerId' => $playerId,
            'x' => $x,
            'y' => $y,
        ]));
        $this->producer->poll(0);
        $this->producer->flush(1000);
    }
}
