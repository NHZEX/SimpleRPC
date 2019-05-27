<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;
use Swoole\Server;

class ServerTcp implements TunnelInterface
{
    /** @var Server */
    private $server;
    /** @var RpcTerminal */
    private $terminal;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function setRpcTerminal(RpcTerminal $terminal): TunnelInterface
    {
        $this->terminal = $terminal;
        return $this;
    }

    /**
     * 发送数据
     * @param TransferFrame   $frame
     * @return bool
     */
    public function send(TransferFrame $frame): bool
    {
        $frame->setWorkerId($this->server->worker_id);
        $data = $frame->packet();
        echo "serverTcpSend >> $frame\n";
        return $this->server->send($frame->getFd(), $data) === strlen($data);
    }
}
