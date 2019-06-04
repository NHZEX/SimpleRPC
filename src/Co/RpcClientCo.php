<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

use Swoole\Client;

class RpcClientCo extends \HZEX\SimpleRpc\RpcClient
{
    protected function onReceive(Client $client, string $data)
    {
        go(function () use ($client, $data) {
            parent::onReceive($client, $data);
        });
    }
}
