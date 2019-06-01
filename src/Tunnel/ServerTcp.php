<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use HZEX\SimpleRpc\Observer\RpcHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;
use Swoole\Server;

class ServerTcp implements TunnelInterface
{
    /** @var Server */
    private $server;
    /** @var RpcTerminal */
    private $terminal;
    /** @var RpcHandleInterface */
    private $handle;

    public function __construct(Server $server, RpcHandleInterface $handle)
    {
        $this->server = $server;
        $this->handle = $handle;
    }

    public function setRpcTerminal(RpcTerminal $terminal): TunnelInterface
    {
        $this->terminal = $terminal;
        return $this;
    }

    public function getWorkerId(): int
    {
        return $this->server->worker_id;
    }

    /**
     * 发送数据
     * @param TransferFrame   $frame
     * @return bool
     */
    public function send(TransferFrame $frame): bool
    {
        if (true === $this->handle->onSend($frame->getFd(), $frame)) {
            return true;
        }

        $frame->setWorkerId($this->server->worker_id);
        $data = $frame->packet();
        // echo "serverTcpSend >> $frame\n";
        return $this->server->send($frame->getFd(), $data) === strlen($data);
    }
}
