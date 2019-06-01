<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use HZEX\SimpleRpc\Exception\RpcUnpackingException;
use HZEX\SimpleRpc\Observer\ClientHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcTerminal;
use Swoole\Client;
use Swoole\Timer;
use think\Container;

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
    /** @var bool */
    private $keep = true;
    /** @var int */
    private $keepTime;
    /** @var RpcTerminal */
    private $terminal;
    /**
     * @var ClientHandleInterface
     */
    private $handle;

    /**
     * 重连定时器
     * @var int
     */
    private $reConnectTime;

    /**
     * TcpClient constructor.
     * @param string                $host
     * @param int                   $port
     * @param ClientHandleInterface $handle
     */
    public function __construct(string $host, int $port, ClientHandleInterface $handle)
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

        $this->handle = $handle;
    }

    /**
     * 启用心跳
     * @param bool $sw
     * @return $this
     */
    public function setKeep(bool $sw)
    {
        $this->keep = $sw;
        return $this;
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
     * 开始连接
     */
    public function connect()
    {
        $this->client->connect($this->host, $this->port);
    }

    /**
     * 断开连接
     */
    public function close()
    {
        $this->client->close();
    }

    /**
     * 尝试重连
     * @param bool $force
     */
    public function reConnect(bool $force = false)
    {
        if ($this->client->isConnected()) {
            if (false === $force) {
                return;
            }
            $this->client->close();
        }
        if (!$this->reConnectTime || !Timer::exists($this->reConnectTime)) {
            $this->reConnectTime = Timer::after(1000, Closure::fromCallable([$this, 'connect']));
        }
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
        $this->terminal = $terminal;
        Container::getInstance()->instance(RpcTerminal::class, $this->terminal);
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
        if (true === $this->handle->onSend($frame)) {
            return true;
        }

        $data = $frame->packet();
        // echo "clientTcpSend: $frame\n";
        return $this->client->send($data) === strlen($data);
    }

    /**
     * 连接成功回调
     * @param Client $client
     */
    private function onConnect(Client $client)
    {
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
        if ($this->handle->onReceive($data)) {
            return;
        }
        $packet = TransferFrame::make($data, null);
        // echo "receive: $packet\n";
        if (false === $packet instanceof TransferFrame) {
            throw new RpcUnpackingException('数据解包错误');
        }

        // 客户端连接成功
        if ($packet::OPCODE_LINK === $packet->getOpcode()) {
            $this->handle->onConnect();
            return;
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

        // https://wiki.swoole.com/wiki/page/172.html
        switch ($client->errCode) {
            case 100: // ENETDOWN Network is down 网络瘫痪
            case 101: // ENETUNREACH Network is unreachable 网络不可达
            case 102: // ENETRESET Network dropped 网络连接丢失
            case 103: // ECONNABORTED Software caused connection 软件导致连接中断
            case 104: // ECONNRESET Connection reset by 连接被重置
            case 110: // ETIMEDOUT Connection timed 连接超时
            case 111: // ECONNREFUSED  Connection refused 拒绝连接
            case 112: // EHOSTDOWN Host is down 主机已关闭
            case 113: // EHOSTUNREACH No route to host 没有主机的路由
                // 发生如上错误则进行重连
                $this->reConnect();
                break;
        }
    }

    /**
     * 连接关闭回调
     * @param Client $client
     */
    private function onClose(Client $client)
    {
        $this->handle->onClose();
        $this->reConnect();
        $this->stopKeep();
    }
}
