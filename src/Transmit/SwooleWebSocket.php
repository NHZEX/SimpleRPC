<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Transmit;

use Swoole\WebSocket\Server;

class SwooleWebSocket implements TransmitInterface
{
    private $server;

    private $fd;

    /**
     * SwooleWebSocket constructor.
     * @param Server $server
     * @param int    $fd
     */
    public function __construct(Server $server, int $fd)
    {
        $this->server = $server;
        $this->fd = $fd;
    }

    /**
     * 发送数据包
     * @param string $data
     * @return bool
     */
    public function __invoke(string $data): bool
    {
        return $this->server->push($this->fd, $data, WEBSOCKET_OPCODE_BINARY);
    }
}
