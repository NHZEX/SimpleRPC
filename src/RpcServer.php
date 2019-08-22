<?php
/** @noinspection PhpUnusedParameterInspection */
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcUnpackingException;
use HZEX\SimpleRpc\Observer\RpcHandleInterface;
use HZEX\SimpleRpc\Protocol\Crypto\CryptoAes;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Struct\Connection;
use HZEX\SimpleRpc\Tunnel\ServerTcp;
use HZEX\TpSwoole\Contract\Event\SwooleServerTcpInterface;
use HZEX\TpSwoole\Manager;
use Swoole\Server;
use Swoole\Timer;
use think\Container;
use Throwable;

/**
 * Class RpcServer
 * @package HZEX\SimpleRpc
 */
class RpcServer implements SwooleServerTcpInterface
{
    protected const PROTOCOL = [
        'open_length_check' => true,  // 启用包长检测协议
        'package_max_length' => 524288, // 包最大长度 512kib
        'package_length_type' => 'N', // 无符号、网络字节序、4字节
        'package_length_offset' => 0,
        'package_body_offset' => 0,
    ];
    /**
     * @var Server
     */
    private $server;
    /**
     * @var Server\Port
     */
    private $serverPort;
    /**
     * @var string
     */
    private $host = '0.0.0.0';
    /**
     * @var int
     */
    private $port = 9502;
    /**
     * @var bool
     */
    private $debug = false;
    /**
     * @var RpcHandleInterface
     */
    private $observer;
    /**
     * @var ServerTcp
     */
    private $tunnel;
    /**
     * @var RpcTerminal
     */
    private $terminal;
    /**
     * @var bool
     */
    private $inited = false;
    /**
     * @var RpcSession[]
     */
    private $fdSession = [];
    /**
     * @var CryptoAes
     */
    private $crypto;

    /**
     * RpcServer constructor.
     * @param RpcHandleInterface $observer
     */
    public function __construct(RpcHandleInterface $observer)
    {
        $this->observer = $observer;
        $this->crypto = new CryptoAes();
    }

    /**
     * @param RpcProvider $provider
     * @param string      $host
     * @param int         $port
     * @return void
     */
    public function start(RpcProvider $provider, string $host = '0.0.0.0', int $port = 9502)
    {
        $this->host = $host ?: $this->host;
        $this->port = $port;

        $this->server = new Server($host, $port, SWOOLE_SOCK_TCP);
        $this->server->set(self::PROTOCOL);
        $this->server->on('WorkerStart', Closure::fromCallable([$this, 'workerStart']));
        $this->server->on('PipeMessage', Closure::fromCallable([$this, 'handlePipeMessage']));
        $this->server->on('WorkerStop', Closure::fromCallable([$this, 'workerStop']));
        $this->server->on('Connect', Closure::fromCallable([$this, 'onConnect']));
        $this->server->on('Receive', Closure::fromCallable([$this, 'onReceive']));
        $this->server->on('Close', Closure::fromCallable([$this, 'onClose']));

        $this->initRpcServer($provider);
        $this->server->start();
    }

    /**
     * @param Manager     $manager
     * @param RpcProvider $provider
     * @param string      $host
     * @param int         $port
     * @return RpcServer
     */
    public function listen(Manager $manager, RpcProvider $provider, string $host = '0.0.0.0', int $port = 9502)
    {
        $this->server = $manager->getSwoole();
        $this->host = $host ?: $this->host;
        $this->port = $port;
        $this->serverPort = $this->server->addlistener($host, $port, SWOOLE_SOCK_TCP);

        $this->serverPort->set(self::PROTOCOL);

        $event = $manager->getEvent();
        $event->listen('swoole.onWorkerStart', Closure::fromCallable([$this, 'workerStart']));
        $event->listen('swoole.onPipeMessage', Closure::fromCallable([$this, 'handlePipeMessage']));
        $event->listen('swoole.onWorkerStop', Closure::fromCallable([$this, 'workerStop']));
        $this->serverPort->on('Connect', Closure::fromCallable([$this, 'onConnect']));
        $this->serverPort->on('Receive', Closure::fromCallable([$this, 'onReceive']));
        $this->serverPort->on('Close', Closure::fromCallable([$this, 'onClose']));

        $this->initRpcServer($provider);
        return $this;
    }

    /**
     * @param RpcProvider $provider
     */
    protected function initRpcServer(RpcProvider $provider)
    {
        $this->tunnel = new ServerTcp($this->server, $this->observer);
        $this->terminal = new RpcTerminal($this->tunnel, $provider);
        // TODO 移除与Tp的硬绑定
        Container::getInstance()->instance(RpcTerminal::class, $this->terminal);
        Container::getInstance()->instance(RpcServer::class, $this);
    }

    /**
     * @param bool $debug
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * @return RpcTerminal
     */
    public function getTerminal(): RpcTerminal
    {
        return $this->terminal;
    }

    /**
     * @return Server
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    /**
     * 是否存在fd
     * @param int $fd
     * @return bool
     */
    public function existSession(int $fd): bool
    {
        return isset($this->fdSession[$fd]);
    }

    /**
     * 工作进程初始化
     * 世界分裂由此开始
     * @param Server $server
     * @param int    $workerId
     */
    public function workerStart(Server $server, int $workerId)
    {
        if ($this->server->taskworker) {
            return;
        }
        $this->inited = true;
        $this->terminal->setSnowFlake(new SnowFlake($workerId));

        TransferFrame::setEncryptHandle(function ($data) {
            $session = RpcContext::getSession();
            $conn = $session->getConnection();
            return  $this->crypto->encrypt($data, '123456789', "{$conn->remote_ip}:{$conn->remote_port}");
        });
        TransferFrame::setDecryptHandle(function ($data) {
            $session = RpcContext::getSession();
            $conn = $session->getConnection();
            return $this->crypto->decrypt($data, '123456789', "{$conn->remote_ip}:{$conn->remote_port}");
        });

        Timer::tick(5000, function () use ($server) {
            $terminal = $this->terminal;
            if ($this->debug) {
                echo "RPC_GC#{$server->worker_id}: {$terminal->gcTransfer()}/{$terminal->countTransfer()}\n";
                echo "RPC_IH#{$server->worker_id}: {$terminal->countInstanceHosting()}\n";
            } else {
                $this->terminal->gcTransfer();
            }
        });
    }

    /**
     * @param Server $server
     */
    public function workerStop(Server $server)
    {
        if ($server->taskworker) {
            return;
        }
    }

    /**
     * 连接进入（Tcp）
     * @param Server $server
     * @param int    $fd
     * @param int    $reactorId
     */
    public function onConnect(Server $server, int $fd, int $reactorId): void
    {
        try {
//            echo "connect#{$server->worker_id}: $fd\n";

            $connection = Connection::make($server->getClientInfo($fd) ?: []);
            $this->fdSession[$fd] = $session = new RpcSession($server->worker_id, $connection);

            if (false === $this->observer->auth($fd, $connection)) {
                $server->close($fd);
                return;
            }

            $session->initPassword();
            RpcContext::setSession($session);

            // 连接建立
            $this->tunnel->send(TransferFrame::link($fd));
            $this->observer->onConnect($fd, $connection);
        } catch (Throwable $throwable) {
            Manager::logServerError($throwable);
        }
    }

    /**
     * 处理进程通信消息
     * @param Server $server
     * @param int    $srcWorkerId
     * @param        $message
     */
    public function handlePipeMessage(Server $server, int $srcWorkerId, $message)
    {
        try {
            // echo "handlePipeMessage#$server->worker_id: $srcWorkerId >> $message\n";
            if ($message instanceof TransferFrame) {
                RpcContext::setFd($fd = $message->getFd());
                RpcContext::setSession($this->fdSession[$fd]);
                $this->terminal->receive($message);
            }
        } catch (Throwable $throwable) {
            Manager::logServerError($throwable);
        }
    }

    /**
     * 收到数据（Tcp）
     * @param Server $server
     * @param int    $fd
     * @param int    $reactorId
     * @param string $data
     */
    public function onReceive(Server $server, int $fd, int $reactorId, string $data): void
    {
        // 建立请求上下文
        RpcContext::setFd($fd);
        RpcContext::setSession($this->fdSession[$fd]);
        try {
            $connection = Connection::make($server->getClientInfo($fd) ?: []);
            if ($this->observer->onReceive($fd, $data, $connection)) {
                return;
            }
            // echo "receive#{$server->worker_id}: $fd >> " . bin2hex(substr($data, 0, 36)) . PHP_EOL;
            $packet = TransferFrame::make($data, $fd);
            if (false === $packet instanceof TransferFrame) {
                throw new RpcUnpackingException('数据解包错误');
            }

            if ($server->worker_id === $packet->getWorkerId()
                || TransferFrame::WORKER_ID_NULL === $packet->getWorkerId()
            ) {
                // echo "receive#$server->worker_id\n";
                $this->terminal->receive($packet);
            } else {
                // echo "forward#$server->worker_id >> {$packet->getWorkerId()}\n";
                $server->sendMessage($packet, $packet->getWorkerId());
            }
        } catch (Throwable $throwable) {
            Manager::logServerError($throwable);
        }
    }

    /**
     * 连接关闭（Tcp）
     * @param Server $server
     * @param int    $fd
     * @param int    $reactorId
     */
    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        if (false === $this->existSession($fd)) {
            return;
        }
        try {
            $connection = Connection::make($server->getClientInfo($fd) ?: []);
            $this->observer->onClose($fd, $connection);
            $this->terminal->destroyInstanceHosting($fd);
            unset($this->fdSession[$fd]);
        } catch (Throwable $throwable) {
            Manager::logServerError($throwable);
        }
    }
}
