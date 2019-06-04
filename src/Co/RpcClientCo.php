<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

use HZEX\SimpleRpc\RpcClient;
use Swoole\Client;

class RpcClientCo extends RpcClient
{
    protected function onReceive(Client $client, string $data)
    {
        go(function () use ($client, $data) {
            parent::onReceive($client, $data);
        });
    }
}
