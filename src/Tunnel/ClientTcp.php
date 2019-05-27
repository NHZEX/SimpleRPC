<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use HZEX\SimpleRpc\Exception\RpcUnpackingException;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;
use Swoole\Client;
use Swoole\Timer;

/**
 * Tcp 通道
 * Class TcpClient
 * @package HZEX\SimpleRpc\Tunnel
 */
class ClientTcp implements TunnelInterface
{
    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var Client */
    private $client;
    /** @var bool  */
    private $keep = false;
    /** @var int */
    private $keepTime;
    /** @var RpcTerminal */
    private $terminal;

    /**
     * TcpClient constructor.
     * @param string $host
     * @param int    $port
     */
    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;

        $this->client = new Client(SWOOLE_SOCK_TCP, true);

        $this->client->set([
            'open_length_check' => true,  // 启用包长检测协议
            'package_max_length' => 524288, // 包最大长度 512kib
            'package_length_type' => 'N', // 无符号、网络字节序、4字节
            'package_length_offset' => 0,
            'package_body_offset' => 0,
        ]);

        $this->client->on('Connect', Closure::fromCallable([$this, 'onConnect']));
        $this->client->on('Receive', Closure::fromCallable([$this, 'onReceive']));
        $this->client->on('Error', Closure::fromCallable([$this, 'onError']));
        $this->client->on('Close', Closure::fromCallable([$this, 'onClose']));
    }

    /**
     * 开始心跳
     */
    private function startKeep()
    {
        if ($this->keep) {
            $this->keepTime = Timer::tick(1000, function () {
                if (false === $this->client->isConnected()) {
                    $this->stopKeep();
                    return;
                }
                $this->terminal->ping();
            });
        }
    }

    /**
     * 停止心跳
     */
    private function stopKeep()
    {
        if ($this->keepTime && Timer::exists($this->keepTime)) {
            Timer::clear($this->keepTime);
        }
        $this->keepTime = null;
    }

    /**
     * 开始连接服务器
     */
    public function connect()
    {
        $this->client->connect($this->host, $this->port);
    }

    public function reConnect(bool $force = false)
    {
        if ($this->client->isConnected()) {
            if (false === $force) {
                return;
            }
            $this->client->close();
        }
        Timer::after(1000, Closure::fromCallable([$this, 'connect']));
    }

    /**
     * 设置关联终端
     * @param RpcTerminal $terminal
     * @return TunnelInterface
     */
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
        $data = $frame->packet();
        echo "clientTcpSend: $frame\n";
        return $this->client->send($data) === strlen($data);
    }

    /**
     * 连接成功回调
     * @param Client $client
     */
    private function onConnect(Client $client)
    {
        ['host' => $host, 'port' => $port] = $client->getsockname();
        echo "connect {$host}:{$port}\n";

        $this->startKeep();
    }

    /**
     * 收到数据回调
     * @param Client $client
     * @param string $data
     * @throws RpcUnpackingException
     * @throws RpcFunctionInvokeException
     */
    private function onReceive(Client $client, string $data)
    {
        $packet = TransferFrame::make($data, null);
        echo "receive: $packet\n";
        if (false === $packet instanceof TransferFrame) {
            throw new RpcUnpackingException('数据解包错误');
        }
        $this->terminal->receive($packet);
    }

    /**
     * 发生错误回调
     * @param Client $client
     */
    private function onError(Client $client)
    {
        echo "error {$client->errCode}: " . swoole_strerror($client->errCode) . PHP_EOL;

        $this->reConnect();
    }

    /**
     * 连接关闭回调
     * @param Client $client
     */
    private function onClose(Client $client)
    {
        echo "link close\n";

        $this->reConnect();
        $this->stopKeep();
    }
}
