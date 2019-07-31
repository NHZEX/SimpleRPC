<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use HZEX\SimpleRpc\Observer\ClientHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;
use Swoole\Client;

/**
 * Tcp 通道
 * Class TcpClient
 * @package HZEX\SimpleRpc\Tunnel
 */
class ClientTcp2 implements TunnelInterface
{
    /**
     * @var Client
     */
    private $client;
    /**
     * @var ClientHandleInterface
     */
    private $handle;
    /**
     * @var bool
     */
    private $isStop = false;

    /**
     * TcpClient constructor.
     * @param Client                $client
     * @param ClientHandleInterface $observer
     */
    public function __construct(Client $client, ClientHandleInterface $observer)
    {
        $this->client = $client;
        $this->handle = $observer;
    }

    /**
     * 获取客户对象
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * 设置关联终端
     * @param RpcTerminal $terminal
     * @return TunnelInterface
     */
    public function setRpcTerminal(RpcTerminal $terminal): TunnelInterface
    {
        return $this;
    }

    public function getWorkerId(): int
    {
        return 1;
    }

    /**
     * 发送数据
     * @param TransferFrame $frame
     * @return bool
     */
    public function send(TransferFrame $frame): bool
    {
        if ($this->isStop) {
            return true;
        }
        if (true === $this->handle->onSend($frame)) {
            return true;
        }

        $data = $frame->packet();
        // echo "clientTcpSend: $frame\n";
        return $this->client->send($data) === strlen($data);
    }

    /**
     * 停止发送数据
     */
    public function stopSend(): void
    {
        $this->isStop = true;
    }
}
