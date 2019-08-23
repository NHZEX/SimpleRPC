<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use HZEX\SimpleRpc\Exception\RpcException;
use HZEX\SimpleRpc\Observer\ClientHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;
use Swoole\Coroutine\Client;

/**
 * Tcp 通道
 * Class TcpClient
 * @package HZEX\SimpleRpc\Tunnel
 */
class ClientTcp implements TunnelInterface
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
     * @throws RpcException
     */
    public function send(TransferFrame $frame): bool
    {
        if ($this->isStop) {
            return true;
        }
        if (true === $this->handle->onSend($frame)) {
            return true;
        }
        // 打包数据
        $data = $frame->packet();
        // 如果连接无效则中止发送
        if (!$this->client->isConnected()) {
            return false;
        }
        // echo "clientTcpSend: $frame\n";
        if ($this->client->send($data) !== strlen($data)) {
            $errMsg = "rpc send data error: {$this->client->errCode}#{$this->client->errMsg}";
            throw new RpcException($errMsg, RPC_SEND_DATA_EXCEPTION);
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
