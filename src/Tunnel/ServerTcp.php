<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Tunnel;

use HZEX\SimpleRpc\RpcTerminal;
use Swoole\Server;

class ServerTcp implements TunnelInterface
{
    /** @var Server */
    private $server;
    /** @var RpcTerminal */
    private $terminal;

    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    public function setRpcTerminal(RpcTerminal $terminal): TunnelInterface
    {
        $this->terminal = $terminal;
        return $this;
    }

    /**
     * 发送数据
     * @param string   $data
     * @param int|null $fd
     * @return bool
     */
    public function send(string $data, ?int $fd = null): bool
    {
        var_dump('serverTcpSend: ' . bin2hex($data));
        return $this->server->send($fd, $data) === strlen($data);
    }
}
