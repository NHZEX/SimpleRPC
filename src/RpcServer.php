<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Exception\RpcUnpackingException;
use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\Tunnel\ServerTcp;
use HZEX\TpSwoole\Facade\Event;
use Swoole\Server;
use Throwable;

class RpcServer
{
    /** @var Server */
    private $server;
    /** @var ServerTcp */
    private $tunnel;
    /** @var Server\Port */
    private $port;
    /** @var RpcTerminal */
    private $terminal;
    /** @var Closure */
    private $eventConnect;
    /** @var Closure */
    private $eventClose;
    /** @var bool */
    private $inited = false;

    private $fdCache = [];

    /**
     * @param Server      $server
     * @param RpcProvider $provider
     * @return RpcServer
     */
    public static function listen(Server $server, RpcProvider $provider)
    {
        $that = new self();

        $that->server = $server;
        $that->port = $server->addlistener('0.0.0.0', 9502, SWOOLE_SOCK_TCP);

        $that->port->set([
            'open_length_check' => true,  // 启用包长检测协议
            'package_max_length' => 524288, // 包最大长度 512kib
            'package_length_type' => 'N', // 无符号、网络字节序、4字节
            'package_length_offset' => 0,
            'package_body_offset' => 0,
        ]);

        $that->port->on('Connect', Closure::fromCallable([$that, 'onConnect']));
        $that->port->on('Receive', Closure::fromCallable([$that, 'onReceive']));
        $that->port->on('Close', Closure::fromCallable([$that, 'onClose']));

        $that->tunnel = new ServerTcp($server);
        $that->terminal = new RpcTerminal($that->tunnel, $provider);

        Event::listen('swoole.onWorkerStart', Closure::fromCallable([$that, 'workerInit']));
        Event::listen('swoole.onPipeMessage', Closure::fromCallable([$that, 'handlePipeMessage']));

        return $that;
    }

    /**
     * 工作进程初始化
     * 世界分裂由此开始
     * @param Server $server
     * @param int    $workerId
     */
    public function workerInit(Server $server, int $workerId)
    {
        if ($this->server->taskworker) {
            return;
        }
        $this->inited = true;

        echo "rpc:worker#$workerId = {$server->worker_id} = {$server->worker_pid}\n";

        $server->tick(5000, function () use ($server) {
            echo "RPC_GC#{$server->worker_id}: {$this->terminal->gcTransfer()}/{$this->terminal->countTransfer()}\n";
        });
    }

    /**
     * 处理进程通信消息
     * @param Server $server
     * @param int    $srcWorkerId
     * @param        $message
     */
    public function handlePipeMessage(Server $server, int $srcWorkerId, $message)
    {
        echo "handlePipeMessage#$server->worker_id: $srcWorkerId >> $message\n";
        if ($message instanceof TransferFrame) {
            try {
                $this->terminal->receive($message);
            } catch (Throwable $throwable) {
                echo (string) $throwable;
            }
        }
    }

    /**
     * @param Closure $eventConnect
     */
    public function setEventConnect(Closure $eventConnect): void
    {
        $this->eventConnect = $eventConnect;
    }

    /**
     * @param Closure $eventClose
     */
    public function setEventClose(Closure $eventClose): void
    {
        $this->eventClose = $eventClose;
    }


    private function __construct()
    {
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
    public function existFd(int $fd): bool
    {
        return isset($this->fdCache[$fd]);
    }

    /**
     * 连接进入（Tcp）
     * @param Server $server
     * @param int    $fd
     * @param int    $reactorId
     */
    private function onConnect(Server $server, int $fd, int $reactorId): void
    {
        echo "connect#{$server->worker_id}: $fd\n";
        $this->fdCache[$fd] = $server->worker_id;
        // TODO 添加Id绑定
        if ($this->eventConnect instanceof Closure) {
            call_user_func($this->eventConnect, $this, $fd);
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
            // echo "receive#{$server->worker_id}: $fd >> " . bin2hex(substr($data, 0, 36)) . PHP_EOL;
            $packet = TransferFrame::make($data, $fd);
            if (false === $packet instanceof TransferFrame) {
                throw new RpcUnpackingException('数据解包错误');
            }

            if ($server->worker_id === $packet->getWorkerId()) {
                echo "receive#$server->worker_id\n";
                $this->terminal->receive($packet);
            } else {
                echo "forward#$server->worker_id >> {$packet->getWorkerId()}\n";
                $server->sendMessage($packet, $packet->getWorkerId());
            }
        } catch (Throwable $throwable) {
            echo (string) $throwable;
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
        echo "close#{$server->worker_id}: $fd, $reactorId\n";
        unset($this->fdCache[$fd]);
        // TODO 移除Id绑定
        if ($this->eventClose instanceof Closure) {
            call_user_func($this->eventClose, $this, $fd);
        }
    }
}
