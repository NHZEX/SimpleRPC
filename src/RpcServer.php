<?php
/** @noinspection PhpUnusedParameterInspection */
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcFrameException;
use HZEX\SimpleRpc\Observer\RpcHandleInterface;
use HZEX\SimpleRpc\Protocol\Crypto\CryptoAes;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Struct\Connection;
use HZEX\SimpleRpc\Tunnel\ServerTcp;
use Psr\Log\LoggerInterface;
use Swoole\Server;
use Throwable;
use unzxin\zswCore\Contract\Events\SwooleServerTcpInterface;
use unzxin\zswCore\Event;

/**
 * Class RpcServer
 * @package HZEX\SimpleRpc
 */
class RpcServer implements SwooleServerTcpInterface
{
    protected const PROTOCOL = [
        'open_length_check'     => true,  // 启用包长检测协议
        'package_max_length'    => RPC_PACKAGE_MAX_LENGTH, // 包最大长度 1MB
        'package_length_type'   => 'N', // 无符号、网络字节序、4字节
        'package_length_offset' => 0,
        'package_body_offset'   => 0,
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
     * @var LoggerInterface
     */
    private $logger;
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
     * @var Connection[]
     */
    private $fdConn = [];
    /**
     * @var string
     */
    private $cryptoKey;
    /**
     * @var string
     */
    private $cryptoRealKey;
    /**
     * @var CryptoAes
     */
    private $crypto;
    /**
     * @var Closure
     */
    private $onError;

    /**
     * RpcServer constructor.
     * @param RpcHandleInterface $observer
     */
    public function __construct(RpcHandleInterface $observer)
    {
        $this->observer = $observer;
        $this->crypto   = new CryptoAes();
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
     * @param Server      $server
     * @param Event       $event
     * @param RpcProvider $provider
     * @param string      $host
     * @param int         $port
     * @return RpcServer
     */
    public function listen(
        Server $server,
        Event $event,
        RpcProvider $provider,
        string $host = '0.0.0.0',
        int $port = 9502
    ) {
        $this->server     = $server;
        $this->host       = $host ?: $this->host;
        $this->port       = $port;
        $this->serverPort = $this->server->addlistener($host, $port, SWOOLE_SOCK_TCP);

        $this->serverPort->set(self::PROTOCOL);

        $event->onSwooleWorkerStart(Closure::fromCallable([$this, 'workerStart']));
        $event->onSwoolePipeMessage(Closure::fromCallable([$this, 'handlePipeMessage']));
        $event->onSwooleWorkerStop(Closure::fromCallable([$this, 'workerStop']));
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
        $this->tunnel   = new ServerTcp($this->server, $this->observer);
        $this->terminal = new RpcTerminal($this->tunnel, $provider);

        Container::getInstance()->rpcTerminal = $this->terminal;

        // 设置全局通信密钥
        $this->cryptoKey     = openssl_random_pseudo_bytes(16);
        $this->cryptoRealKey = hash('md5', $this->cryptoKey, true);
    }

    /**
     * @param Closure $onError
     */
    public function setOnError(Closure $onError): void
    {
        $this->onError = $onError;
    }

    /**
     * @param bool $debug
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
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
        return isset($this->fdConn[$fd]);
    }

    /**
     * 工作进程初始化
     * 分裂由此开始
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

        TransferFrame::setEncryptHandle(function ($data, TransferFrame $frame) {
            $conn = $this->getConnection($frame->getFd());
            return $this->crypto->encrypt($data, $this->cryptoRealKey, "{$conn->remote_ip}:{$conn->remote_port}");
        });
        TransferFrame::setDecryptHandle(function ($data, TransferFrame $frame) {
            $conn = $this->getConnection($frame->getFd());
            return $this->crypto->decrypt($data, $this->cryptoRealKey, "{$conn->remote_ip}:{$conn->remote_port}");
        });

        $this->observer->onWorkerStart($this, $workerId);
    }

    /**
     * @param Server $server
     */
    public function workerStop(Server $server)
    {
        if ($server->taskworker) {
            return;
        }

        $this->observer->onWorkerStop($this);
    }

    /**
     * @param int $fd
     * @return bool|Connection|null
     */
    protected function isAuthorize(int $fd)
    {
        if (empty($this->fdConn[$fd])) {
            $conn = $this->createConnection($fd);
            if (empty($conn)) {
                return false;
            }
            $result = $this->observer->auth($fd, $conn);
            if (false === $result || empty($result)) {
                return false;
            }
            // 如果响应整数则绑定为Uid
            if (is_int($result)) {
                if (null === $conn->uid) {
                    $conn->uid = $result;
                    $this->server->bind($fd, $result);
                } elseif ($result !== $conn->uid) {
                    // 如果绑定id 与 验证id 不一致则为异常连接
                    return false;
                }
            }
            return $conn;
        } else {
            return $this->fdConn[$fd];
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
            $this->log("rpc connect#{$server->worker_id}: {$fd}");
            if (!($conn = $this->isAuthorize($fd))) {
                // 验证失败，断开连接
                $server->close($fd);
                return;
            }

            // 连接建立
            $frame = TransferFrame::link($fd);
            $frame->setBody(serialize([
                'crypto_key' => $this->cryptoKey,
            ]));
            $this->tunnel->send($frame);
            $this->observer->onConnect($fd, $conn);
        } catch (Throwable $throwable) {
            $this->handleException($throwable);
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
            if (is_array($message)
                && 2 === count($message)
                && $message[0] instanceof TransferFrame
            ) {
                $this->log("rpc pipe#{$server->worker_id}: {$srcWorkerId}");
                /** @var $frame TransferFrame */
                /** @var $uid int */
                [$frame, $uid] = $message;
                // 设置Rpc当前请求Fd
                RpcContext::setFd($frame->getFd());
                RpcContext::setUid($uid);
                $this->terminal->receive($frame);
            }
        } catch (Throwable $throwable) {
            $this->handleException($throwable);
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
        try {
            if (!($conn = $this->isAuthorize($fd))) {
                // 验证失败，断开连接
                $server->close($fd);
                return;
            }
            if ($this->observer->onReceive($fd, $data, $conn)) {
                return;
            }
            // 设置Rpc当前请求Fd
            RpcContext::setFd($fd);
            RpcContext::setUid($conn->uid);

            try {
                $packet = TransferFrame::make($data, $fd);
            } catch (RpcFrameException $invalidFrame) {
                $message = "invalid frame discard #{$fd}, {$invalidFrame->getCode()}#{$invalidFrame->getMessage()}";
                $this->logger->error('rpc server: ' . $message);
                return;
            }

            if ($server->worker_id === $packet->getWorkerId()
                || TransferFrame::WORKER_ID_NULL === $packet->getWorkerId()
            ) {
                $this->log("receive#{$server->worker_id}");
                $this->terminal->receive($packet);
            } else {
                $this->log("forward#{$server->worker_id} >> {$packet->getWorkerId()}");
                $server->sendMessage([$packet, $conn->uid], $packet->getWorkerId());
            }
        } catch (Throwable $throwable) {
            $this->handleException($throwable);
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
            $conn = $this->getConnection($fd);
            $this->observer->onClose($fd, $conn);
            $this->terminal->destroyInstanceHosting($fd);
            $this->delConnection($fd);
        } catch (Throwable $throwable) {
            $this->handleException($throwable);
        }
    }

    protected function handleException(Throwable $e)
    {
        if ($this->onError instanceof Closure) {
            call_user_func($this->onError, $e);
        } else {
            $output = "============== Error ==============\n";
            $next = $e;
            do {
                if ($next !== $e) {
                    $output = "======== Next\n";
                }
                $output .= "E: [{$e->getCode()}] {$e->getMessage()}\n";
                $output .= "F: {$e->getCode()}:{$e->getLine()}\n";
                $output .= "T: {$e->getTraceAsString()}\n";
            } while ($next = $next->getPrevious());
            $output .= "=============== End ===============\n";
            echo $output;
        }
    }

    /**
     * 创建连接信息缓存
     * @param int $fd
     * @return Connection|null
     */
    public function createConnection(int $fd): ?Connection
    {
        $conn = $this->getConnection($fd);
        if (null === $conn) {
            return null;
        }
        return $this->fdConn[$fd] = $conn;
    }

    /**
     * 获取连接信息
     * @param int $fd
     * @return Connection|null
     */
    public function getConnection(int $fd): ?Connection
    {
        // 如果属于当前进程则直接获取
        if (isset($this->fdConn[$fd])) {
            return $this->fdConn[$fd];
        }
        // 自动获取连接信息
        $info = $this->server->getClientInfo($fd);
        if (false === $info) {
            return null;
        }
        return Connection::make($info);
    }

    /**
     * 移除连接信息
     * @param int $fd
     */
    public function delConnection(int $fd): void
    {
        unset($this->fdConn[$fd]);
    }

    /**
     * @param       $message
     * @param array $context
     */
    public function log($message, array $context = []): void
    {
        if ($this->debug) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->debug($message, $context);
            } else {
                echo $message . PHP_EOL;
            }
        }
    }
}
