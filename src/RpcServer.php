<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc;

use Closure;
use HZEX\SimpleRpc\Tunnel\ServerTcp;
use Swoole\Server;
use Throwable;

class RpcServer
{
    /** @var Server */
    private $server;
    /** @var Server\Port */
    private $port;
    /** @var RpcTerminal */
    private $terminal;

    /**
     * @param Server $server
     * @return RpcServer
     */
    public static function listen(Server $server)
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

        $tunnel = new ServerTcp($server);
        $that->terminal = new RpcTerminal($tunnel);

        return $that;
    }

    private function __construct()
    {
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
            echo "receive#{$server->worker_id}: $fd >> " . bin2hex(substr($data, 0, 36)) . PHP_EOL;
            $this->terminal->receive($data, $fd);
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
        echo "close#{$server->worker_id}: $fd\n";
    }
}
