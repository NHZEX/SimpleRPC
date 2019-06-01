<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFunctionInvokeException;
use HZEX\SimpleRpc\Exception\RpcUnpackingException;
use HZEX\SimpleRpc\Observer\ClientHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Tunnel\ClientTcp2;
use Swoole\Client;
use Swoole\Timer;
use think\Container;

class RpcClient
{
    /**
     * @var ClientHandleInterface
     */
    private $observer;
    /**
     * @var Client
     */
    private $client;
    /**
     * @var ClientTcp2
     */
    private $tunnel;
    /**
     * @var RpcTerminal
     */
    private $terminal;
    /**
     * @var string
     */
    private $host;
    /**
     * @var int
     */
    private $port;
    /**
     * 自动重连
     * @var bool
     */
    private $reConnect = true;
    /**
     * @var bool
     */
    private $keep = true;
    /**
     * @var int
     */
    private $keepTime;
    /**
     * 重连定时器
     * @var int
     */
    private $reConnectTime;

    /**
     * RpcClient constructor.
     * @param ClientHandleInterface $observer
     */
    public function __construct(ClientHandleInterface $observer)
    {
        $this->observer = $observer;
    }

    /**
     * @param RpcProvider $provider
     * @param string      $host
     * @param int         $port
     * @return $this
     */
    public function connect(RpcProvider $provider, string $host, int $port)
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

        $this->tunnel = new ClientTcp2($this->client, $this->observer);
        $this->terminal = new RpcTerminal($this->tunnel, $provider);
        $this->init();

        $this->client->connect($this->host, $this->port);

        return $this;
    }

    /**
     * 初始化
     */
    protected function init()
    {
        $this->terminal->setSnowFlake(new SnowFlake(1));
        Container::getInstance()->instance(RpcTerminal::class, $this->terminal);
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
     * 获取通信终端
     * @return RpcTerminal
     */
    public function getTerminal()
    {
        return $this->terminal;
    }

    /**
     * 是否已经连接
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->client->isConnected();
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

    public function setReConnect(bool $sw)
    {
        $this->reConnect = $sw;
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
        if ($this->observer->onReceive($data)) {
            return;
        }
        $packet = TransferFrame::make($data, null);
        // echo "receive: $packet\n";
        if (false === $packet instanceof TransferFrame) {
            throw new RpcUnpackingException('数据解包错误');
        }

        // 客户端连接成功
        if ($packet::OPCODE_LINK === $packet->getOpcode()) {
            $this->observer->onConnect();
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
                if ($this->reConnect) {
                    $this->reConnect();
                }
                break;
        }
    }

    /**
     * 连接关闭回调
     * @param Client $client
     */
    private function onClose(Client $client)
    {
        $this->observer->onClose();
        $this->stopKeep();
        if ($this->reConnect) {
            $this->reConnect();
        }
    }
}
