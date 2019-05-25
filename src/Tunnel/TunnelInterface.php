<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use HZEX\SimpleRpc\RpcTerminal;

interface TunnelInterface
{
    public function setRpcTerminal(RpcTerminal $terminal): self;

    /**
     * 发送数据
     * @param string   $data
     * @param int|null $fd
     * @return bool
     */
    public function send(string $data, ?int $fd): bool;
}
