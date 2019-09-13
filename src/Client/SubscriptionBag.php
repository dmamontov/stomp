<?php

namespace React\Stomp\Client;

class SubscriptionBag
{
    public $i = 0;
    public $data = [];

    public function add($destination, $ack)
    {
        $subscriptionId = $this->i;
        $this->data[$subscriptionId] = [
            'destination' => $destination,
            'ack' => $ack,
        ];

        ++$this->i;

        return $subscriptionId;
    }
}
