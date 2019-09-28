<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Observer;

use HZEX\SimpleRpc\Protocol\TransferFrame;
use HZEX\SimpleRpc\RpcServer;
use HZEX\SimpleRpc\Struct\Connection;

interface RpcHandleInterface
{
    /**
     * @param RpcServer $server
     * @param int       $workerId
     */
    public function onWorkerStart(RpcServer $server, int $workerId): void;

    /**
     * @param RpcServer $server
     */
    public function onWorkerStop(RpcServer $server): void;

    /**
     * @param int        $fd
     * @param Connection $connection
     * @return bool|int
     */
    public function auth(int $fd, Connection $connection);

    /**
     * @param int        $fd
     * @param Connection $connection
     */
    public function onConnect(int $fd, Connection $connection): void;

    /**
     * @param int        $fd
     * @param Connection $connection
     */
    public function onClose(int $fd, Connection $connection): void;

    /**
     * @param int        $fd
     * @param string     $data
     * @param Connection $connection
     * @return bool|null
     */
    public function onReceive(int $fd, string $data, Connection $connection): ?bool;

    /**
     * @param int           $fd
     * @param TransferFrame $frame
     * @return bool|null
     */
    public function onSend(int $fd, TransferFrame $frame): ?bool;
}
