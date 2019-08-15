<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcClientConnectException;
use HZEX\SimpleRpc\Exception\RpcClientException;
use HZEX\SimpleRpc\Exception\RpcClientRecvException;
use HZEX\SimpleRpc\Exception\RpcUnpackingException;
use HZEX\SimpleRpc\Observer\ClientHandleInterface;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Tunnel\ClientTcp;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Timer;
use think\Container;

class RpcClient
{
    /**
     * @var ClientHandleInterface
     */
    private $observer;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var Client
     */
    private $client;
    /**
     * @var ClientTcp
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
     * 是否连接
     * @var bool
     */
    protected $isConnected = false;
    /**
     * 重连间隔
     * @var int
     */
    private $reConnectInterval = 2;
    /**
     * @var int
     */
    private $keepTimeId;

    /**
     * RpcClient constructor.
     * @param ClientHandleInterface $observer
     * @param LoggerInterface       $logger
     */
    public function __construct(ClientHandleInterface $observer, LoggerInterface $logger)
    {
        $this->observer = $observer;
        $this->logger = $logger;
    }

    /**
     * 连接双向Rpc
     * @param RpcProvider $provider
     * @param string      $host
     * @param int         $port
     * @return $this
     */
    public function connect(RpcProvider $provider, string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;

        $this->client = new Client(SWOOLE_SOCK_TCP);

        $this->client->set([
            'open_length_check' => true,  // 启用包长检测协议
            'package_max_length' => 524288, // 包最大长度 512kib
            'package_length_type' => 'N', // 无符号、网络字节序、4字节
            'package_length_offset' => 0,
            'package_body_offset' => 0,
            //'timeout' => 0.5,
            //'connect_timeout' => 1.0,
            //'write_timeout' => 10.0,
            //'read_timeout' => 5.0,
        ]);

        $this->tunnel = new ClientTcp($this->client, $this->observer);
        $this->terminal = new RpcTerminal($this->tunnel, $provider);
        $this->terminal->setSnowFlake(new SnowFlake(1));
        // TODO 移除与Tp的硬绑定
        Container::getInstance()->instance(RpcTerminal::class, $this->terminal);

        $this->isConnected = true;
        $this->loop();
        return $this;
    }

    /**
     * 处理循环
     */
    private function loop()
    {
        go(function () {
            while ($this->isConnected || $this->reConnect) {
                try {
                    // 连接服务端
                    $this->clientConnect($this->host, $this->port);
                    $this->logger->info("rpc client connect: {$this->host}:{$this->port}");
                    // 触发连接成功事件
                    go(Closure::fromCallable([$this, 'onConnect']));
                    // 查询接受事件
                    while (true) {
                        $result = $this->clientRecv();
                        go(Closure::fromCallable([$this, 'handleReceive']), $result);
                    }
                } catch (RpcClientException $ce) {
                    $this->logger->warning("rpc client error: {$ce->getCode()} {$ce->getMessage()}");
                    // 重连判断
                    if ($ce->isDisconnect()) {
                        // 如果不是连接失败则触发连接关闭事件
                        if (!$ce instanceof RpcClientConnectException) {
                            // 触发连接断开事件
                            go(Closure::fromCallable([$this, 'onClose']));
                        }
                        $this->client->close();
                        if ($this->reConnect) {
                            Coroutine::sleep($this->reConnectInterval);
                            continue;
                        } else {
                            break;
                        }
                    }
                }
            }
        });
    }

    /**
     * @param string $host
     * @param int    $port
     * @param float  $timeout
     * @param int    $sock_flag
     * @return bool
     * @throws RpcClientConnectException
     */
    private function clientConnect(string $host, int $port, float $timeout = 0.5, int $sock_flag = 0): bool
    {
        if (false === $this->client->connect($host, $port, $timeout, $sock_flag)) {
            throw new RpcClientConnectException($this->client->errMsg, $this->client->errCode);
        }
        return true;
    }

    /**
     * @param float $timeout
     * @return string
     * @throws RpcClientRecvException
     */
    private function clientRecv(float $timeout = -1): string
    {
        $data = $this->client->recv($timeout);
        if (false === $data) {
            // 返回false，检测$client->errCode获取错误原因
            throw new RpcClientRecvException($this->client->errMsg, $this->client->errCode);
        }
        if ('' === $data) {
            // 返回空字符串表示服务端主动关闭连接
            throw new RpcClientRecvException('Host is down [empty recv]', 112);
        }
        return $data;
    }

    /**
     * 关闭 Rpc 连接
     */
    public function close()
    {
        $this->stopKeep();
        $this->reConnect = false;
        $this->isConnected = false;
        $this->tunnel->stop();
        if ($this->isConnected()) {
            $this->client->close();
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
     * 开始心跳
     */
    private function startKeep()
    {
        $this->keepTimeId = Timer::tick(1000, function () {
            if (false === $this->client->isConnected()) {
                $this->stopKeep();
                return;
            }
            $this->terminal->ping();
        });
    }

    /**
     * 停止心跳
     */
    private function stopKeep()
    {
        if ($this->keepTimeId && Timer::exists($this->keepTimeId)) {
            Timer::clear($this->keepTimeId);
        }
        $this->keepTimeId = null;
    }

    /**
     * 连接成功回调
     */
    protected function onConnect()
    {
        $this->startKeep();
    }

    /**
     * @param string $data
     * @throws Exception\RpcInvalidResponseException
     * @throws Exception\RpcSendDataException
     * @throws RpcUnpackingException
     */
    protected function handleReceive(string $data)
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
     * 连接关闭回调
     */
    protected function onClose()
    {
        $this->observer->onClose();
        $this->stopKeep();
    }
}
