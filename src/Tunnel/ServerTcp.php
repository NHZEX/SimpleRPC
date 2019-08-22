<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use HZEX\SimpleRpc\Exception\RpcException;
use HZEX\SimpleRpc\Observer\RpcHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;
use Swoole\Server;

class ServerTcp implements TunnelInterface
{
    /**
     * @var Server
     */
    private $server;
    /**
     * @var RpcTerminal
     */
    private $terminal;
    /**
     * @var RpcHandleInterface
     */
    private $handle;
    /**
     * @var bool
     */
    private $isStop = false;

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
     * @param TransferFrame $frame
     * @return bool
     * @throws RpcException
     */
    public function send(TransferFrame $frame): bool
    {
        if ($this->isStop) {
            return true;
        }
        if (true === $this->handle->onSend($frame->getFd(), $frame)) {
            return true;
        }

        $frame->setWorkerId($this->server->worker_id);
        $data = $frame->packet();
        // echo "serverTcpSend >> $frame\n";
        if (false === $this->server->send($frame->getFd(), $data)) {
            $errCode = $this->server->getLastError();
            $errMsg = swoole_strerror($errCode, 9);
            throw new RpcException("rpc send data error: {$errCode}#{$errMsg}", RPC_SEND_DATA_EXCEPTION);
        }
        return true;
    }

    /**
     * 停止发送数据
     * @return void
     */
    public function stop(): void
    {
        $this->isStop = true;
    }

    /**
     * 是否停止发送
     * @return bool
     */
    public function isStop(): bool
    {
        return $this->isStop;
    }
}
